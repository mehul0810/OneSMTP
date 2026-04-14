<?php

declare(strict_types=1);

namespace OneSMTP\Queue;

use OneSMTP\Dispatch\DispatchPolicyInterface;

final class RetryScheduler
{
    public const ACTION_HOOK = 'onesmtp_process_retry';
    private const GROUP       = 'onesmtp';

    private DispatchPolicyInterface $dispatchPolicy;

    public function __construct(DispatchPolicyInterface $dispatchPolicy)
    {
        $this->dispatchPolicy = $dispatchPolicy;
    }

    public function registerHooks(): void
    {
        add_action(self::ACTION_HOOK, [$this, 'processRetry'], 10, 1);
    }

    public function scheduleRetry(int $messageId, int $attempt): void
    {
        $delay = min(3600, (int) pow(2, max(0, $attempt - 1)) * 60);
        $args  = ['message_id' => $messageId, 'attempt' => $attempt];

        if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::ACTION_HOOK, $args, self::GROUP)) {
            return;
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, self::ACTION_HOOK, $args, self::GROUP);
            return;
        }

        // TODO: Optional WP-Cron fallback can be added if Action Scheduler is unavailable.
    }

    public function processRetry(array $payload): void
    {
        $messageId = isset($payload['message_id']) ? (int) $payload['message_id'] : 0;
        $attempt   = isset($payload['attempt']) ? (int) $payload['attempt'] : 1;

        if ($messageId <= 0) {
            return;
        }

        // TODO: Load message + attempt history from repository.
        // TODO: Ask dispatch policy for next provider.
        // TODO: Execute send through provider adapter.
        // TODO: Persist attempt result and potentially schedule next retry.
        $this->dispatchPolicy->chooseNextProvider($messageId, $attempt, []);
    }
}
