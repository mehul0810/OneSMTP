<?php

declare(strict_types=1);

namespace OneSMTP\Pipeline;

use OneSMTP\Dispatch\DispatchPolicyInterface;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;

final class SendPipeline
{
    private const MAX_RETRIES = 6;

    private MessageRepository $messages;
    private AttemptRepository $attempts;
    private ProviderRepository $providers;
    private EventRepository $events;
    private RetryScheduler $retryScheduler;
    private DispatchPolicyInterface $dispatchPolicy;

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
        DispatchPolicyInterface $dispatchPolicy
    ) {
        $this->messages      = $messages;
        $this->attempts      = $attempts;
        $this->providers     = $providers;
        $this->events        = $events;
        $this->retryScheduler = $retryScheduler;
        $this->dispatchPolicy = $dispatchPolicy;
    }

    public function registerHooks(): void
    {
        add_filter('wp_mail', [$this, 'captureMessage'], 1, 1);
        add_action('wp_mail_succeeded', [$this, 'handleSuccess'], 10, 1);
        add_action('wp_mail_failed', [$this, 'handleFailure'], 10, 1);
    }

    public function captureMessage(array $args): array
    {
        $messageId = $this->messages->create($args, self::MAX_RETRIES);
        if ($messageId <= 0) {
            return $args;
        }

        $fingerprint = $this->buildFingerprint($args);
        $this->inflight[$fingerprint] = $messageId;

        $this->events->add('message_captured', ['subject' => (string) ($args['subject'] ?? '')], $messageId);

        return $args;
    }

    /**
     * @param array<string,mixed> $mailData
     */
    public function handleSuccess(array $mailData): void
    {
        $messageId = $this->resolveMessageId($mailData);
        if ($messageId <= 0) {
            return;
        }

        $attemptNo  = max(1, $this->attempts->getAttemptCountForMessage($messageId) + 1);
        $providerId = $this->pickProvider($messageId, $attemptNo);

        $this->attempts->add([
            'message_id'  => $messageId,
            'attempt_no'  => $attemptNo,
            'provider_id' => $providerId,
            'trigger_type' => 'initial',
            'result'      => 'sent',
        ]);

        $this->messages->markSent($messageId, $providerId);
        $this->events->add('message_sent', ['attempt' => $attemptNo], $messageId, $providerId);
    }

    public function handleFailure(\WP_Error $error): void
    {
        $mailData  = $error->get_error_data('wp_mail_failed');
        $mailData  = is_array($mailData) ? $mailData : [];
        $messageId = $this->resolveMessageId($mailData);

        if ($messageId <= 0) {
            $messageId = $this->messages->create($mailData, self::MAX_RETRIES);
            if ($messageId <= 0) {
                return;
            }
        }

        $attemptNo  = max(1, $this->attempts->getAttemptCountForMessage($messageId) + 1);
        $providerId = $this->pickProvider($messageId, $attemptNo);

        $this->attempts->add([
            'message_id'    => $messageId,
            'attempt_no'    => $attemptNo,
            'provider_id'   => $providerId,
            'trigger_type'  => $attemptNo === 1 ? 'initial' : 'retry',
            'result'        => 'fail',
            'error_code'    => (string) $error->get_error_code(),
            'error_message' => $error->get_error_message(),
        ]);

        if ($attemptNo >= self::MAX_RETRIES) {
            $this->messages->markFailedTerminal($messageId, $attemptNo);
            $this->events->add('terminal_failure', ['attempt' => $attemptNo], $messageId, $providerId);
            return;
        }

        $nextAttempt = $attemptNo + 1;
        $runAt       = $this->retryScheduler->scheduleRetry($messageId, $nextAttempt);
        $this->messages->markRetryScheduled($messageId, $attemptNo, $runAt);
    }

    private function pickProvider(int $messageId, int $attemptNo): ?int
    {
        $providers   = $this->providers->getActiveProviders();
        $lastAttempt = $this->attempts->getLastAttemptForMessage($messageId);
        $lastId      = isset($lastAttempt['provider_id']) ? (int) $lastAttempt['provider_id'] : 0;
        $consecutive = $lastId > 0 ? $this->attempts->countConsecutiveFailuresForProvider($messageId, $lastId) : 0;

        return $this->dispatchPolicy->chooseNextProvider(
            $messageId,
            $attemptNo,
            [
                'providers'                               => $providers,
                'last_provider_id'                        => $lastId,
                'consecutive_failures_for_last_provider'  => $consecutive,
            ]
        );
    }

    /**
     * @param array<string,mixed> $mailData
     */
    private function resolveMessageId(array $mailData): int
    {
        $fingerprint = $this->buildFingerprint($mailData);

        return isset($this->inflight[$fingerprint]) ? (int) $this->inflight[$fingerprint] : 0;
    }

    /**
     * @param array<string,mixed> $mailData
     */
    private function buildFingerprint(array $mailData): string
    {
        $normalized = [
            'to'      => $mailData['to'] ?? [],
            'subject' => (string) ($mailData['subject'] ?? ''),
            'message' => (string) ($mailData['message'] ?? ''),
            'headers' => $mailData['headers'] ?? [],
        ];

        return hash('sha256', wp_json_encode($normalized));
    }
}
