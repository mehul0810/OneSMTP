<?php

declare(strict_types=1);

namespace OneSMTP\Pipeline;

use OneSMTP\Conflict\MailDeliveryOwnership;
use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Delivery\DeliveryOutcome;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\RateLimit\RateLimitDecision;
use OneSMTP\RateLimit\RateLimiter;
use OneSMTP\Providers\FailureCategory;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Settings\BackgroundSendingSettingsRepository;
use OneSMTP\Settings\SimulationModeSettingsRepository;

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
    private RateLimiter $rateLimiter;
    private BackgroundSendingSettingsRepository $backgroundSending;
    private MailSourceAttributor $sourceAttributor;
    private SimulationModeSettingsRepository $simulationMode;
    private MailDeliveryOwnership $deliveryOwnership;

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
        DeliveryEngine $deliveryEngine,
        ?RateLimiter $rateLimiter = null,
        ?BackgroundSendingSettingsRepository $backgroundSending = null,
        ?MailSourceAttributor $sourceAttributor = null,
        ?SimulationModeSettingsRepository $simulationMode = null,
        ?MailDeliveryOwnership $deliveryOwnership = null
    ) {
        $this->messages = $messages;
        $this->attempts = $attempts;
        $this->providers = $providers;
        $this->events = $events;
        $this->retryScheduler = $retryScheduler;
        $this->deliveryEngine = $deliveryEngine;
        $this->rateLimiter = $rateLimiter ?? new RateLimiter($attempts);
        $this->backgroundSending = $backgroundSending ?? new BackgroundSendingSettingsRepository();
        $this->sourceAttributor = $sourceAttributor ?? new MailSourceAttributor();
        $this->simulationMode = $simulationMode ?? new SimulationModeSettingsRepository();
        $this->deliveryOwnership = $deliveryOwnership ?? new MailDeliveryOwnership();
    }

    public function registerHooks(): void
    {
        if ($this->deliveryOwnership->canAculectDeliver()) {
            add_filter('pre_wp_mail', [$this, 'handlePreWpMail'], 10, 2);
            add_filter('wp_mail', [$this, 'captureMessage'], 1, 1);
        }
        add_action('onesmtp_retry_attempt', [$this, 'handleRetryAttempt'], 10, 5);
        add_action('onesmtp_background_send_attempt', [$this, 'handleBackgroundSendAttempt'], 10, 5);
        add_action('onesmtp_manual_resend', [$this, 'handleManualResend'], 10, 2);
    }

    public function handlePreWpMail($pre, array $atts)
    {
        if ($pre !== null) {
            return $pre;
        }

        if (! $this->deliveryOwnership->canAculectDeliver()) {
            return null;
        }

        if ($this->isSimulationEnabled()) {
            $captured = $this->captureMessage($atts);
            $messageId = $this->resolveMessageId($captured);
            if ($messageId <= 0) {
                return false;
            }
            $this->messages->markSimulated($messageId);
            $this->events->add('message_simulated', ['reason' => 'simulation_mode'], $messageId);

            return true;
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
        $messageUuid = $this->extractMessageUuidFromHeaders($captured['headers'] ?? []);

        if ($this->shouldQueueInitialSend($captured)) {
            $runAt = $this->retryScheduler->scheduleBackgroundSend($messageId, $attemptNo, $messageUuid);

            return is_int($runAt) && $runAt > 0;
        }

        return $this->sendWithRateLimit(
            $messageId,
            $attemptNo,
            'initial',
            $captured,
            fn () => $this->deliveryEngine->deliver($messageId, $attemptNo, $captured, null)
        );
    }

    public function handleBackgroundSendAttempt($messageId, int $attemptNo = 1, array $payload = [], ?string $messageUuid = null, ?int $providerId = null): void
    {
        $messageId = (int) $messageId;
        if ($messageId <= 0 || $attemptNo <= 0) {
            return;
        }

        if (! $this->deliveryOwnership->canAculectDeliver()) {
            $this->events->add('delivery_paused', ['reason' => 'external_mail_owner', 'trigger' => 'background'], $messageId);
            return;
        }

        if ($this->simulateExistingMessage($messageId, 'background')) {
            return;
        }

        $payload = $payload !== [] ? $payload : $this->messages->getPayloadForMessage($messageId);
        if ($payload === []) {
            $this->messages->markFailedTerminal($messageId, max(1, $attemptNo - 1));
            $this->events->add('terminal_failure', ['reason' => 'background_payload_missing'], $messageId);
            return;
        }

        $this->sendWithRateLimit(
            $messageId,
            $attemptNo,
            'background',
            $payload,
            fn () => $this->deliveryEngine->deliver($messageId, $attemptNo, $payload, $providerId !== null ? (int) $providerId : null, false, true),
            $messageUuid
        );
    }

    public function captureMessage(array $args): array
    {
        $args = $this->sourceAttributor->withSource($args);

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

        if (! $this->deliveryOwnership->canAculectDeliver()) {
            $this->events->add('delivery_paused', ['reason' => 'external_mail_owner', 'trigger' => 'retry'], $messageId, $providerId);
            return;
        }

        if ($this->simulateExistingMessage($messageId, 'retry')) {
            return;
        }

        $payload = $payload !== [] ? $payload : $this->messages->getPayloadForMessage($messageId);
        if ($payload === []) {
            $this->messages->markFailedTerminal($messageId, $attemptNo);
            $this->events->add('terminal_failure', ['reason' => 'missing_payload'], $messageId, $providerId);
            return;
        }

        $this->sendWithRateLimit(
            $messageId,
            $attemptNo,
            'retry',
            $payload,
            fn () => $this->deliveryEngine->deliver($messageId, $attemptNo, $payload, $providerId !== null ? (int) $providerId : null, false, true),
            $messageUuid
        );
    }

    public function handleManualResend(int $messageId, int $forcedProviderId = 0): void
    {
        $this->resendMessage($messageId, $forcedProviderId > 0 ? $forcedProviderId : null);
    }

    public function resendMessage(int $messageId, ?int $forcedProviderId = null): bool
    {
        if (! $this->deliveryOwnership->canAculectDeliver()) {
            $this->events->add('delivery_paused', ['reason' => 'external_mail_owner', 'trigger' => 'manual_resend'], $messageId, $forcedProviderId);
            return false;
        }

        if ($this->simulateExistingMessage($messageId, 'manual_resend')) {
            return true;
        }

        $payload = $this->messages->getPayloadForMessage($messageId);
        if ($payload === []) {
            return false;
        }

        $attemptNo = max(1, $this->attempts->getAttemptCountForMessage($messageId) + 1);
        return $this->sendWithRateLimit(
            $messageId,
            $attemptNo,
            'manual_resend',
            $payload,
            fn () => $this->deliveryEngine->deliver($messageId, $attemptNo, $payload, $forcedProviderId)
        );
    }

    private function sendWithRateLimit(
        int $messageId,
        int $attemptNo,
        string $triggerType,
        array $payload,
        callable $send,
        ?string $messageUuid = null
    ): bool {
        if (! $this->rateLimiter->acquireSendLock()) {
            return $this->deferForRateLimit(
                $messageId,
                $attemptNo,
                $triggerType,
                $payload,
                RateLimitDecision::limited(5, 'backpressure_lock', 0, 0),
                $messageUuid
            );
        }

        try {
            $decision = $this->rateLimiter->evaluate();
            if (! $decision->canSend()) {
                return $this->deferForRateLimit($messageId, $attemptNo, $triggerType, $payload, $decision, $messageUuid);
            }

            $outcome = $send();
            if (! $outcome instanceof DeliveryOutcome) {
                return false;
            }

            return $this->persistOutcome($messageId, $attemptNo, $triggerType, $payload, $outcome, $messageUuid);
        } finally {
            $this->rateLimiter->releaseSendLock();
        }
    }

    private function deferForRateLimit(
        int $messageId,
        int $attemptNo,
        string $triggerType,
        array $payload,
        RateLimitDecision $decision,
        ?string $messageUuid = null
    ): bool {
        if ($messageUuid === null || $messageUuid === '') {
            $messageUuid = $this->extractMessageUuidFromHeaders($payload['headers'] ?? []);
        }

        if ($messageUuid === '') {
            $message = $this->messages->find($messageId);
            $messageUuid = is_array($message) ? (string) ($message['message_uuid'] ?? '') : '';
        }

        $runAt = $this->retryScheduler->scheduleRetry($messageId, $attemptNo, $messageUuid, $decision->getRetryAfter());
        if (! is_int($runAt) || $runAt <= 0) {
            $this->messages->markFailedTerminal($messageId, max(1, $attemptNo - 1));
            $this->events->add(
                'terminal_failure',
                ['attempt' => $attemptNo, 'reason' => 'rate_limit_scheduler_unavailable'],
                $messageId
            );

            return false;
        }

        $this->events->add(
            'rate_limit_deferred',
            [
                'attempt'     => $attemptNo,
                'trigger'     => $triggerType,
                'window'      => $decision->getWindow(),
                'limit'       => $decision->getLimit(),
                'used'        => $decision->getUsed(),
                'retry_after' => $decision->getRetryAfter(),
                'run_at'      => gmdate('c', $runAt),
            ],
            $messageId
        );

        return true;
    }

    private function persistOutcome(
        int $messageId,
        int $attemptNo,
        string $triggerType,
        array $payload,
        DeliveryOutcome $outcome,
        ?string $messageUuid = null
    ): bool {
        if ($outcome->isDeferred()) {
            return $this->deferForProviderQuota($messageId, $attemptNo, $triggerType, $payload, $outcome, $messageUuid);
        }

        $this->recordAttempt($messageId, $attemptNo, $triggerType, $outcome);
        $this->deliveryEngine->releaseQuotaReservation($outcome->getProviderId(), $outcome->getQuotaReservationToken());

        if ($outcome->isSuccess()) {
            $this->messages->markSent($messageId, $outcome->getProviderId());
            $this->events->add('message_sent', ['attempt' => $attemptNo, 'trigger' => $triggerType], $messageId, $outcome->getProviderId());
            return true;
        }

        if ($attemptNo >= self::MAX_RETRIES) {
            $this->messages->markFailedTerminal($messageId, $attemptNo);
            $this->events->add(
                'terminal_failure',
                ['attempt' => $attemptNo, 'reason' => $outcome->getCode(), 'failure_category' => $outcome->getFailureCategory()],
                $messageId,
                $outcome->getProviderId()
            );
            return false;
        }

        // A provider-specific authentication, quota, or policy error can still
        // be isolated to that provider. Try healthy alternates before treating
        // the logical message as terminal; only the final outcome determines
        // whether the queue continues.
        if (FailureCategory::canFailover($outcome->getFailureCategory())) {
            $failoverResult = $this->attemptImmediateFailover($messageId, $attemptNo, $triggerType, $payload, $outcome, $messageUuid);
            if ($failoverResult !== null) {
                return $failoverResult;
            }
        }

        if (! $outcome->shouldRetry()) {
            $this->messages->markFailedTerminal($messageId, $attemptNo);
            $this->events->add(
                'terminal_failure',
                ['attempt' => $attemptNo, 'reason' => $outcome->getCode(), 'failure_category' => $outcome->getFailureCategory()],
                $messageId,
                $outcome->getProviderId()
            );
            return false;
        }

        if ($this->hasAttachments($payload)) {
            $this->messages->markFailedTerminal($messageId, $attemptNo);
            $this->events->add(
                'terminal_failure',
                ['attempt' => $attemptNo, 'reason' => 'attachment_retry_not_persisted'],
                $messageId,
                $outcome->getProviderId()
            );

            return false;
        }

        return $this->scheduleNextRetry($messageId, $attemptNo, $triggerType, $payload, $outcome, $messageUuid);
    }

    private function recordAttempt(int $messageId, int $attemptNo, string $triggerType, DeliveryOutcome $outcome): void
    {
        $this->attempts->add([
            'message_id' => $messageId,
            'attempt_no' => $attemptNo,
            'provider_id' => $outcome->getProviderId() > 0 ? $outcome->getProviderId() : null,
            'trigger_type' => $triggerType,
            'result' => $outcome->isSuccess() ? 'sent' : 'fail',
            'error_code' => $outcome->isSuccess() ? null : $outcome->getCode(),
            'error_message' => $outcome->isSuccess() ? null : $outcome->getMessage(),
            'failure_category' => $outcome->isSuccess() ? null : $outcome->getFailureCategory(),
            'latency_ms' => $outcome->getLatencyMs(),
            'provider_message_id' => $outcome->getProviderMessageId(),
        ]);
    }

    /**
     * Try each other active provider once before falling back to the normal
     * delayed retry schedule. A null result means no alternate provider is
     * available and the caller should schedule the next retry.
     */
    private function attemptImmediateFailover(
        int $messageId,
        int $attemptNo,
        string $triggerType,
        array $payload,
        DeliveryOutcome $initialOutcome,
        ?string $messageUuid
    ): ?bool {
        if ($initialOutcome->getProviderId() <= 0) {
            return null;
        }

        $activeProviders = $this->eligibleProvidersForPayload($this->providers->getActiveProviders(), $payload);
        $fallbackBudget = min(
            max(0, count($activeProviders) - 1),
            max(0, self::MAX_RETRIES - $attemptNo)
        );
        if ($fallbackBudget <= 0) {
            return null;
        }

        $nextAttempt = $attemptNo + 1;
        $lastOutcome = $initialOutcome;
        for ($index = 0; $index < $fallbackBudget; $index++, $nextAttempt++) {
            $fallback = $this->deliveryEngine->deliver(
                $messageId,
                $nextAttempt,
                $payload,
                null,
                true
            );
            if ($fallback->isDeferred()) {
                return $this->deferForProviderQuota($messageId, $nextAttempt, 'failover', $payload, $fallback, $messageUuid);
            }

            $this->recordAttempt($messageId, $nextAttempt, 'failover', $fallback);
            $this->deliveryEngine->releaseQuotaReservation($fallback->getProviderId(), $fallback->getQuotaReservationToken());
            $lastOutcome = $fallback;

            if ($fallback->isSuccess()) {
                $this->messages->markSent($messageId, $fallback->getProviderId());
                $this->events->add(
                    'message_sent',
                    ['attempt' => $nextAttempt, 'trigger' => 'failover', 'failed_attempt' => $attemptNo],
                    $messageId,
                    $fallback->getProviderId()
                );

                return true;
            }

            if (! FailureCategory::canFailover($fallback->getFailureCategory())) {
                $this->messages->markFailedTerminal($messageId, $nextAttempt);
                $this->events->add(
                    'terminal_failure',
                    ['attempt' => $nextAttempt, 'reason' => $fallback->getCode(), 'failure_category' => $fallback->getFailureCategory()],
                    $messageId,
                    $fallback->getProviderId()
                );

                return false;
            }
        }

        if ($this->hasAttachments($payload)) {
            $this->messages->markFailedTerminal($messageId, $nextAttempt - 1);
            $this->events->add(
                'terminal_failure',
                ['attempt' => $nextAttempt - 1, 'reason' => 'attachment_retry_not_persisted'],
                $messageId,
                $lastOutcome->getProviderId()
            );

            return false;
        }

        return $this->scheduleNextRetry($messageId, $nextAttempt - 1, $triggerType, $payload, $lastOutcome, $messageUuid);
    }

    private function deferForProviderQuota(
        int $messageId,
        int $attemptNo,
        string $triggerType,
        array $payload,
        DeliveryOutcome $outcome,
        ?string $messageUuid = null
    ): bool {
        if ($messageUuid === null || $messageUuid === '') {
            $messageUuid = $this->extractMessageUuidFromHeaders($payload['headers'] ?? []);
        }

        if ($messageUuid === '') {
            $message = $this->messages->find($messageId);
            $messageUuid = is_array($message) ? (string) ($message['message_uuid'] ?? '') : '';
        }

        $runAt = $this->retryScheduler->scheduleRetry($messageId, $attemptNo, $messageUuid, max(1, $outcome->getRetryAfter()));
        if (! is_int($runAt) || $runAt <= 0) {
            $this->events->add(
                'provider_quota_defer_failed',
                ['attempt' => $attemptNo, 'trigger' => $triggerType, 'reason' => 'scheduler_backend_unavailable'],
                $messageId
            );
            $this->messages->markFailedTerminal($messageId, max(1, $attemptNo));
            $this->events->add(
                'terminal_failure',
                ['attempt' => $attemptNo, 'trigger' => $triggerType, 'reason' => 'provider_quota_scheduler_unavailable'],
                $messageId
            );

            return false;
        }

        $context = [
            'attempt' => $attemptNo,
            'trigger' => $triggerType,
            'retry_after' => max(1, $outcome->getRetryAfter()),
            'run_at' => gmdate('c', $runAt),
        ];
        if ($outcome->getNextCapacityAt() !== null) {
            $context['next_capacity_at'] = gmdate('c', (int) $outcome->getNextCapacityAt());
        }

        $this->events->add('provider_quota_deferred', $context, $messageId);

        return true;
    }

    private function scheduleNextRetry(
        int $messageId,
        int $attemptNo,
        string $triggerType,
        array $payload,
        DeliveryOutcome $outcome,
        ?string $messageUuid
    ): bool {

        $nextAttempt = $attemptNo + 1;
        if ($messageUuid === null || $messageUuid === '') {
            $message = $this->messages->find($messageId);
            $messageUuid = is_array($message) ? (string) ($message['message_uuid'] ?? '') : '';
        }

        $runAt = $this->retryScheduler->scheduleRetry($messageId, $nextAttempt, $messageUuid);
        if (is_int($runAt) && $runAt > 0) {
            return false;
        }

        $this->messages->markFailedTerminal($messageId, $attemptNo);
        $this->events->add('terminal_failure', ['attempt' => $attemptNo, 'reason' => 'retry_backend_unavailable'], $messageId, $outcome->getProviderId());

        return false;
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

    private function shouldQueueInitialSend(array $payload): bool
    {
        if ($this->hasAttachments($payload)) {
            return false;
        }

        if (! $this->backgroundSending->get()->isEnabled()) {
            return false;
        }

        $meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];
        $source = isset($meta['source']) ? sanitize_key((string) $meta['source']) : '';

        if ($source === 'rest_test_email') {
            return false;
        }

        $mode = '';
        if (isset($payload['onesmtp_send_mode'])) {
            $mode = sanitize_key((string) $payload['onesmtp_send_mode']);
        } elseif (isset($meta['onesmtp_send_mode'])) {
            $mode = sanitize_key((string) $meta['onesmtp_send_mode']);
        }

        return $mode !== 'sync' && $mode !== 'synchronous';
    }

    private function isSimulationEnabled(): bool
    {
        return $this->simulationMode->get()->isEnabled();
    }

    private function simulateExistingMessage(int $messageId, string $trigger): bool
    {
        if ($messageId <= 0 || ! $this->isSimulationEnabled()) {
            return false;
        }

        $this->messages->markSimulated($messageId);
        $this->events->add('message_simulated', ['reason' => 'simulation_mode', 'trigger' => $trigger], $messageId);

        return true;
    }

    private function hasAttachments(array $payload): bool
    {
        $attachments = $payload['attachments'] ?? [];

        return is_array($attachments) ? $attachments !== [] : trim((string) $attachments) !== '';
    }

    /** @param array<int,array<string,mixed>> $providers */
    private function eligibleProvidersForPayload(array $providers, array $payload): array
    {
        if (! $this->hasAttachments($payload)) {
            return $providers;
        }

        return array_values(array_filter(
            $providers,
            static fn (array $provider): bool => ProviderTypes::supportsCapability(
                (string) ($provider['adapter_type'] ?? ''),
                'attachments'
            )
        ));
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
