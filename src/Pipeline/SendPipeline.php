<?php

declare(strict_types=1);

namespace OneSMTP\Pipeline;

use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Delivery\DeliveryOutcome;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;

final class SendPipeline
{
    private const MAX_RETRIES = 6;
    private const HEADER_MESSAGE_UUID = 'X-OneSMTP-Message-ID';

    private MessageRepository $messages;
    private AttemptRepository $attempts;
    private ProviderRepository $providers;
    private EventRepository $events;
    private RetryScheduler $retryScheduler;
    private DeliveryEngine $deliveryEngine;

    /**
     * @var array<string,int>
     */
    private array $inflight = [];

    public function __construct(
        MessageRepository $messages,
        AttemptRepository $attempts,
        ProviderRepository $providers,
        EventRepository $events,
        RetryScheduler $retryScheduler,
        DeliveryEngine $deliveryEngine
    ) {
        $this->messages = $messages;
        $this->attempts = $attempts;
        $this->providers = $providers;
        $this->events = $events;
        $this->retryScheduler = $retryScheduler;
        $this->deliveryEngine = $deliveryEngine;
    }

    public function registerHooks(): void
    {
        add_filter('pre_wp_mail', [$this, 'handlePreWpMail'], 10, 2);
        add_filter('wp_mail', [$this, 'captureMessage'], 1, 1);
        add_action('onesmtp_retry_attempt', [$this, 'handleRetryAttempt'], 10, 5);
        add_action('onesmtp_manual_resend', [$this, 'handleManualResend'], 10, 2);
    }

    public function handlePreWpMail($pre, array $atts)
    {
        if ($pre !== null) {
            return $pre;
        }

        if ($this->providers->getActiveProviders() === []) {
            return null;
        }

        $captured = $this->captureMessage($atts);
        $messageId = $this->resolveMessageId($captured);
        if ($messageId <= 0) {
            return false;
        }

        $attemptNo = max(1, $this->attempts->getAttemptCountForMessage($messageId) + 1);
        $outcome = $this->deliveryEngine->deliver($messageId, $attemptNo, $captured, null);
        $this->persistOutcome($messageId, $attemptNo, 'initial', $captured, $outcome);

        return $outcome->isSuccess();
    }

    public function captureMessage(array $args): array
    {
        $messageUuid = $this->extractMessageUuidFromHeaders($args['headers'] ?? []);
        if ($messageUuid === '') {
            $messageUuid = (string) wp_generate_uuid4();
            $args['headers'] = $this->appendMessageUuidHeader($args['headers'] ?? [], $messageUuid);
        }

        $existing = $this->messages->findByUuid($messageUuid);
        if (is_array($existing) && isset($existing['id'])) {
            $messageId = (int) $existing['id'];
            $this->messages->updatePayload($messageId, $args);
            $this->inflight[$this->buildFingerprint($args)] = $messageId;

            return $args;
        }

        $messageId = $this->messages->create($args, self::MAX_RETRIES, $messageUuid);
        if ($messageId > 0) {
            $this->inflight[$this->buildFingerprint($args)] = $messageId;
            $this->events->add(
                'message_captured',
                ['subject' => (string) ($args['subject'] ?? ''), 'message_uuid' => $messageUuid],
                $messageId
            );
        }

        return $args;
    }

    public function handleRetryAttempt($messageId, int $attemptNo, ?int $providerId = null, array $payload = [], ?string $messageUuid = null): void
    {
        $messageId = (int) $messageId;
        if ($messageId <= 0 || $attemptNo <= 0) {
            return;
        }

        $payload = $payload !== [] ? $payload : $this->messages->getPayloadForMessage($messageId);
        if ($payload === []) {
            $this->messages->markFailedTerminal($messageId, $attemptNo);
            $this->events->add('terminal_failure', ['reason' => 'missing_payload'], $messageId, $providerId);
            return;
        }

        $outcome = $this->deliveryEngine->deliver($messageId, $attemptNo, $payload, $providerId !== null ? (int) $providerId : null);
        $this->persistOutcome($messageId, $attemptNo, 'retry', $payload, $outcome, $messageUuid);
    }

    public function handleManualResend(int $messageId, int $forcedProviderId = 0): void
    {
        $this->resendMessage($messageId, $forcedProviderId > 0 ? $forcedProviderId : null);
    }

    public function resendMessage(int $messageId, ?int $forcedProviderId = null): bool
    {
        $payload = $this->messages->getPayloadForMessage($messageId);
        if ($payload === []) {
            return false;
        }

        $attemptNo = max(1, $this->attempts->getAttemptCountForMessage($messageId) + 1);
        $outcome = $this->deliveryEngine->deliver($messageId, $attemptNo, $payload, $forcedProviderId);
        $this->persistOutcome($messageId, $attemptNo, 'manual_resend', $payload, $outcome);

        return $outcome->isSuccess();
    }

    private function persistOutcome(
        int $messageId,
        int $attemptNo,
        string $triggerType,
        array $payload,
        DeliveryOutcome $outcome,
        ?string $messageUuid = null
    ): void {
        $this->attempts->add([
            'message_id' => $messageId,
            'attempt_no' => $attemptNo,
            'provider_id' => $outcome->getProviderId() > 0 ? $outcome->getProviderId() : null,
            'trigger_type' => $triggerType,
            'result' => $outcome->isSuccess() ? 'sent' : 'fail',
            'error_code' => $outcome->isSuccess() ? null : $outcome->getCode(),
            'error_message' => $outcome->isSuccess() ? null : $outcome->getMessage(),
            'provider_message_id' => $outcome->getProviderMessageId(),
        ]);

        if ($outcome->isSuccess()) {
            $this->messages->markSent($messageId, $outcome->getProviderId());
            $this->events->add('message_sent', ['attempt' => $attemptNo, 'trigger' => $triggerType], $messageId, $outcome->getProviderId());
            return;
        }

        if ($attemptNo >= self::MAX_RETRIES) {
            $this->messages->markFailedTerminal($messageId, $attemptNo);
            $this->events->add('terminal_failure', ['attempt' => $attemptNo, 'reason' => $outcome->getCode()], $messageId, $outcome->getProviderId());
            return;
        }

        $nextAttempt = $attemptNo + 1;
        if ($messageUuid === null || $messageUuid === '') {
            $message = $this->messages->find($messageId);
            $messageUuid = is_array($message) ? (string) ($message['message_uuid'] ?? '') : '';
        }

        $runAt = $this->retryScheduler->scheduleRetry($messageId, $nextAttempt, $messageUuid);
        if (is_int($runAt) && $runAt > 0) {
            $this->messages->markRetryScheduled($messageId, $attemptNo, $runAt);
            return;
        }

        $this->messages->markFailedTerminal($messageId, $attemptNo);
        $this->events->add('terminal_failure', ['attempt' => $attemptNo, 'reason' => 'retry_backend_unavailable'], $messageId, $outcome->getProviderId());
    }

    private function resolveMessageId(array $mailData): int
    {
        $messageUuid = $this->extractMessageUuidFromHeaders($mailData['headers'] ?? []);
        if ($messageUuid !== '') {
            $row = $this->messages->findByUuid($messageUuid);
            if (is_array($row) && isset($row['id'])) {
                return (int) $row['id'];
            }
        }

        $fingerprint = $this->buildFingerprint($mailData);

        return isset($this->inflight[$fingerprint]) ? (int) $this->inflight[$fingerprint] : 0;
    }

    private function buildFingerprint(array $mailData): string
    {
        $normalized = [
            'to' => $mailData['to'] ?? [],
            'subject' => (string) ($mailData['subject'] ?? ''),
            'message' => (string) ($mailData['message'] ?? ''),
            'headers' => $mailData['headers'] ?? [],
        ];

        return hash('sha256', wp_json_encode($normalized));
    }

    /**
     * @param array<int|string,mixed>|string $headers
     * @return array<int,string>
     */
    private function appendMessageUuidHeader($headers, string $messageUuid): array
    {
        $normalizedHeaders = [];

        if (is_string($headers) && $headers !== '') {
            $normalizedHeaders = preg_split('/\r\n|\r|\n/', $headers) ?: [];
        } elseif (is_array($headers)) {
            $normalizedHeaders = $headers;
        }

        $normalizedHeaders[] = self::HEADER_MESSAGE_UUID . ': ' . $messageUuid;

        return array_values(
            array_filter(
                array_map('strval', $normalizedHeaders),
                static fn (string $header): bool => $header !== ''
            )
        );
    }

    /**
     * @param array<int|string,mixed>|string $headers
     */
    private function extractMessageUuidFromHeaders($headers): string
    {
        if (is_string($headers)) {
            $headers = preg_split('/\r\n|\r|\n/', $headers) ?: [];
        }

        if (! is_array($headers)) {
            return '';
        }

        foreach ($headers as $header) {
            if (! is_string($header)) {
                continue;
            }

            if (stripos($header, self::HEADER_MESSAGE_UUID . ':') !== 0) {
                continue;
            }

            $value = trim(substr($header, strlen(self::HEADER_MESSAGE_UUID) + 1));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
