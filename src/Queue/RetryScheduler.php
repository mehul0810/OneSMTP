<?php

declare(strict_types=1);

namespace OneSMTP\Queue;

use OneSMTP\Dispatch\DispatchPolicyInterface;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;

final class RetryScheduler
{
    public const ACTION_HOOK = 'onesmtp_process_retry';
    public const BACKGROUND_ACTION_HOOK = 'onesmtp_process_background_send';
    private const GROUP       = 'onesmtp';
    private const MAX_RETRIES = 6;
    private const LOCK_TTL    = 120;

    private DispatchPolicyInterface $dispatchPolicy;
    private MessageRepository $messages;
    private AttemptRepository $attempts;
    private ProviderRepository $providers;
    private EventRepository $events;

    public function __construct(
        DispatchPolicyInterface $dispatchPolicy,
        MessageRepository $messages,
        AttemptRepository $attempts,
        ProviderRepository $providers,
        EventRepository $events
    ) {
        $this->dispatchPolicy = $dispatchPolicy;
        $this->messages       = $messages;
        $this->attempts       = $attempts;
        $this->providers      = $providers;
        $this->events         = $events;
    }

    public function registerHooks(): void
    {
        add_action(self::ACTION_HOOK, [$this, 'processRetry'], 10, 3);
        add_action(self::BACKGROUND_ACTION_HOOK, [$this, 'processBackgroundSend'], 10, 3);
    }

    public function getDelayForAttempt(int $attempt): int
    {
        return min(3600, (int) pow(2, max(0, $attempt - 1)) * 60);
    }

    public function scheduleRetry(int $messageId, int $attempt, ?string $messageUuid = null, ?int $delayOverride = null): ?int
    {
        $message     = $this->messages->find($messageId);
        $maxAttempts = $this->getMaxAttempts($message);
        $status      = isset($message['status']) ? (string) $message['status'] : '';

        if (in_array($status, ['sent', 'failed'], true)) {
            $this->events->add('retry_not_scheduled', ['reason' => 'terminal_status', 'attempt' => $attempt], $messageId);

            return null;
        }

        if ($attempt > $maxAttempts) {
            $this->messages->markFailedTerminal($messageId, $maxAttempts);
            $this->events->add('terminal_failure', ['reason' => 'max_retries_boundary', 'attempt' => $attempt], $messageId);

            return null;
        }

        $delay    = $delayOverride !== null ? max(1, $delayOverride) : $this->getDelayForAttempt($attempt);
        $runAt    = time() + $delay;
        $args     = [$messageId, $attempt, (string) $messageUuid];
        $scheduleKey = $this->scheduleKey($messageId, $attempt);

        if (get_transient($scheduleKey) !== false) {
            return $runAt;
        }

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::ACTION_HOOK, $args, self::GROUP)) {
            return $runAt;
        }

        if (function_exists('as_schedule_single_action')) {
            $scheduled = as_schedule_single_action($runAt, self::ACTION_HOOK, $args, self::GROUP);

            if ($scheduled) {
                set_transient($scheduleKey, $runAt, $delay + self::LOCK_TTL);
                $this->messages->markRetryScheduled($messageId, $attempt, $runAt);
                $this->events->add('retry_scheduled', ['attempt' => $attempt, 'run_at' => gmdate('c', $runAt)], $messageId);

                return $runAt;
            }
        }

        $this->events->add('retry_schedule_failed', ['reason' => 'scheduler_backend_unavailable', 'attempt' => $attempt], $messageId);

        return null;
    }

    public function scheduleBackgroundSend(int $messageId, int $attempt = 1, ?string $messageUuid = null): ?int
    {
        $message = $this->messages->find($messageId);
        $status  = isset($message['status']) ? (string) $message['status'] : '';

        if (in_array($status, ['sent', 'failed'], true)) {
            $this->events->add('background_send_not_scheduled', ['reason' => 'terminal_status', 'attempt' => $attempt], $messageId);

            return null;
        }

        $runAt = time() + 1;
        $args = [$messageId, max(1, $attempt), (string) $messageUuid];
        $scheduleKey = $this->backgroundScheduleKey($messageId, max(1, $attempt));

        if (get_transient($scheduleKey) !== false) {
            return $runAt;
        }

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::BACKGROUND_ACTION_HOOK, $args, self::GROUP)) {
            return $runAt;
        }

        if (function_exists('as_schedule_single_action')) {
            $scheduled = as_schedule_single_action($runAt, self::BACKGROUND_ACTION_HOOK, $args, self::GROUP);

            if ($scheduled) {
                set_transient($scheduleKey, $runAt, self::LOCK_TTL);
                $this->events->add('background_send_queued', ['attempt' => max(1, $attempt), 'run_at' => gmdate('c', $runAt)], $messageId);

                return $runAt;
            }
        }

        $this->events->add('background_send_schedule_failed', ['reason' => 'scheduler_backend_unavailable', 'attempt' => max(1, $attempt)], $messageId);

        return null;
    }

    public function processBackgroundSend($messageId, int $attempt = 1, ?string $messageUuid = null): void
    {
        if (is_array($messageId)) {
            $attempt = isset($messageId['attempt']) ? (int) $messageId['attempt'] : $attempt;
            $messageUuid = isset($messageId['message_uuid']) ? (string) $messageId['message_uuid'] : $messageUuid;
            $messageId = isset($messageId['message_id']) ? (int) $messageId['message_id'] : 0;
        }

        $messageId = (int) $messageId;
        $attempt = max(1, $attempt);

        if ($messageId <= 0) {
            return;
        }

        if (! $this->acquireLock($messageId, $attempt)) {
            return;
        }

        try {
            $this->releaseBackgroundScheduleLock($messageId, $attempt);
            $this->processBackgroundSendInternal($messageId, $attempt, $messageUuid);
        } finally {
            $this->releaseLock($messageId, $attempt);
        }
    }

    public function processRetry($messageId, int $attempt = 1, ?string $messageUuid = null): void
    {
        if (is_array($messageId)) {
            $attempt   = isset($messageId['attempt']) ? (int) $messageId['attempt'] : $attempt;
            $messageUuid = isset($messageId['message_uuid']) ? (string) $messageId['message_uuid'] : $messageUuid;
            $messageId = isset($messageId['message_id']) ? (int) $messageId['message_id'] : 0;
        }

        $messageId = (int) $messageId;

        if ($messageId <= 0 || $attempt <= 0) {
            return;
        }

        if (! $this->acquireLock($messageId, $attempt)) {
            return;
        }

        try {
            $this->releaseScheduleLock($messageId, $attempt);
            $this->processRetryInternal($messageId, $attempt, $messageUuid);
        } finally {
            $this->releaseLock($messageId, $attempt);
        }
    }

    private function processRetryInternal(int $messageId, int $attempt, ?string $messageUuid): void
    {
        $message = $this->messages->find($messageId);
        if ($message === null && is_string($messageUuid) && $messageUuid !== '') {
            $message = $this->messages->findByUuid($messageUuid);
            if (is_array($message) && isset($message['id'])) {
                $messageId = (int) $message['id'];
            }
        }

        if ($message === null) {
            $this->events->add('retry_skipped', ['reason' => 'message_missing', 'attempt' => $attempt], $messageId);
            return;
        }

        $status = isset($message['status']) ? (string) $message['status'] : 'pending';
        if (in_array($status, ['sent', 'failed'], true)) {
            return;
        }

        $maxAttempts = $this->getMaxAttempts($message);
        if ($attempt > $maxAttempts) {
            $this->messages->markFailedTerminal($messageId, $maxAttempts);
            $this->events->add('terminal_failure', ['reason' => 'max_retries_exceeded'], $messageId);
            return;
        }

        if (($messageUuid === null || $messageUuid === '') && isset($message['message_uuid'])) {
            $messageUuid = (string) $message['message_uuid'];
        }

        $payload = $this->messages->getPayloadForMessage($messageId);
        if ($payload === []) {
            $this->events->add('retry_skipped', ['reason' => 'payload_missing', 'attempt' => $attempt], $messageId);
            return;
        }

        $providers   = $this->providers->getActiveProviders();
        $lastAttempt = $this->attempts->getLastAttemptForMessage($messageId);
        $lastId      = isset($lastAttempt['provider_id']) ? (int) $lastAttempt['provider_id'] : 0;
        $consecutive = $lastId > 0 ? $this->attempts->countConsecutiveFailuresForProvider($messageId, $lastId) : 0;

        $providerId = $this->dispatchPolicy->chooseNextProvider(
            $messageId,
            $attempt,
            [
                'providers'                               => $providers,
                'last_provider_id'                        => $lastId,
                'consecutive_failures_for_last_provider'  => $consecutive,
            ]
        );

        $this->messages->markRetryRunning($messageId, $attempt, $providerId);
        do_action('onesmtp_retry_attempt', $messageId, $attempt, $providerId, $payload, $messageUuid);
        $this->events->add('retry_dispatched', ['attempt' => $attempt], $messageId, $providerId);
    }

    private function processBackgroundSendInternal(int $messageId, int $attempt, ?string $messageUuid): void
    {
        $message = $this->messages->find($messageId);
        if ($message === null && is_string($messageUuid) && $messageUuid !== '') {
            $message = $this->messages->findByUuid($messageUuid);
            if (is_array($message) && isset($message['id'])) {
                $messageId = (int) $message['id'];
            }
        }

        if ($message === null) {
            $this->events->add('background_send_skipped', ['reason' => 'message_missing', 'attempt' => $attempt], $messageId);
            return;
        }

        $status = isset($message['status']) ? (string) $message['status'] : 'queued';
        if (in_array($status, ['sent', 'failed'], true)) {
            $this->events->add('background_send_skipped', ['reason' => 'terminal_status', 'attempt' => $attempt], $messageId);
            return;
        }

        if (($messageUuid === null || $messageUuid === '') && isset($message['message_uuid'])) {
            $messageUuid = (string) $message['message_uuid'];
        }

        $payload = $this->messages->getPayloadForMessage($messageId);
        if ($payload === []) {
            $this->events->add('background_send_skipped', ['reason' => 'payload_missing', 'attempt' => $attempt], $messageId);
            return;
        }

        $providerId = $this->dispatchPolicy->chooseNextProvider(
            $messageId,
            $attempt,
            [
                'providers' => $this->providers->getActiveProviders(),
                'last_provider_id' => 0,
                'consecutive_failures_for_last_provider' => 0,
            ]
        );

        $this->messages->markRetryRunning($messageId, $attempt, $providerId);
        do_action('onesmtp_background_send_attempt', $messageId, $attempt, $payload, $messageUuid, $providerId);
        $this->events->add('background_send_dispatched', ['attempt' => $attempt], $messageId, $providerId);
    }

    private function getMaxAttempts(?array $message): int
    {
        if (is_array($message) && isset($message['max_attempts'])) {
            return max(1, (int) $message['max_attempts']);
        }

        return self::MAX_RETRIES;
    }

    private function acquireLock(int $messageId, int $attempt): bool
    {
        $lockKey = $this->lockKey($messageId, $attempt);

        if (function_exists('wp_cache_add') && wp_using_ext_object_cache()) {
            return (bool) wp_cache_add($lockKey, 1, self::GROUP, self::LOCK_TTL);
        }

        if (get_transient($lockKey) !== false) {
            return false;
        }

        return set_transient($lockKey, 1, self::LOCK_TTL);
    }

    private function releaseLock(int $messageId, int $attempt): void
    {
        $lockKey = $this->lockKey($messageId, $attempt);

        if (function_exists('wp_cache_delete') && wp_using_ext_object_cache()) {
            wp_cache_delete($lockKey, self::GROUP);
        }

        delete_transient($lockKey);
    }

    private function lockKey(int $messageId, int $attempt): string
    {
        return sprintf('retry_lock_%d_%d', $messageId, $attempt);
    }

    private function scheduleKey(int $messageId, int $attempt): string
    {
        return sprintf('retry_scheduled_%d_%d', $messageId, $attempt);
    }

    private function backgroundScheduleKey(int $messageId, int $attempt): string
    {
        return sprintf('background_scheduled_%d_%d', $messageId, $attempt);
    }

    private function releaseScheduleLock(int $messageId, int $attempt): void
    {
        delete_transient($this->scheduleKey($messageId, $attempt));
    }

    private function releaseBackgroundScheduleLock(int $messageId, int $attempt): void
    {
        delete_transient($this->backgroundScheduleKey($messageId, $attempt));
    }
}
