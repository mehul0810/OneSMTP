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
    }

    public function getDelayForAttempt(int $attempt): int
    {
        return min(3600, (int) pow(2, max(0, $attempt - 1)) * 60);
    }

    public function scheduleRetry(int $messageId, int $attempt, ?string $messageUuid = null): ?int
    {
        if ($attempt > self::MAX_RETRIES) {
            $this->messages->markFailedTerminal($messageId, self::MAX_RETRIES);
            $this->events->add('terminal_failure', ['reason' => 'max_retries_boundary', 'attempt' => $attempt], $messageId);

            return null;
        }

        $delay    = $this->getDelayForAttempt($attempt);
        $runAt    = time() + $delay;
        $args     = [$messageId, $attempt, (string) $messageUuid];

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::ACTION_HOOK, $args, self::GROUP)) {
            return $runAt;
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($runAt, self::ACTION_HOOK, $args, self::GROUP);
            $this->events->add('retry_scheduled', ['attempt' => $attempt, 'run_at' => gmdate('c', $runAt)], $messageId);

            return $runAt;
        }

        $this->events->add('retry_schedule_failed', ['reason' => 'scheduler_backend_unavailable', 'attempt' => $attempt], $messageId);

        return null;
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
            return;
        }

        $status = isset($message['status']) ? (string) $message['status'] : 'pending';
        if (in_array($status, ['sent', 'failed'], true)) {
            return;
        }

        if ($attempt > self::MAX_RETRIES) {
            $this->messages->markFailedTerminal($messageId, self::MAX_RETRIES);
            $this->events->add('terminal_failure', ['reason' => 'max_retries_exceeded'], $messageId);
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

        if (($messageUuid === null || $messageUuid === '') && isset($message['message_uuid'])) {
            $messageUuid = (string) $message['message_uuid'];
        }

        $payload = $this->messages->getPayloadForMessage($messageId);
        do_action('onesmtp_retry_attempt', $messageId, $attempt, $providerId, $payload, $messageUuid);
        $this->events->add('retry_dispatched', ['attempt' => $attempt], $messageId, $providerId);
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
}
