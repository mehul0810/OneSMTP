<?php

declare(strict_types=1);

namespace OneSMTP\Queue;

use OneSMTP\Repository\MessageRepository;

final class QueueDiagnostics
{
    private const OVERDUE_GRACE_SECONDS = 300;

    private ActionSchedulerHealth $schedulerHealth;
    private MessageRepository $messages;
    /** @var callable():int */
    private $clock;

    /**
     * @param callable():int|null $clock
     */
    public function __construct(ActionSchedulerHealth $schedulerHealth, MessageRepository $messages, ?callable $clock = null)
    {
        $this->schedulerHealth = $schedulerHealth;
        $this->messages = $messages;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array{
     *     scheduler_available:bool,
     *     queue_status:string,
     *     queued_count:int,
     *     retry_scheduled_count:int,
     *     retrying_count:int,
     *     failed_count:int,
     *     overdue_retry_count:int,
     *     next_retry_at:?string,
     *     recommended_actions:array<int,string>
     * }
     */
    public function snapshot(): array
    {
        $now = (int) ($this->clock)();
        $summary = $this->messages->getQueueStatusSummary(gmdate('Y-m-d H:i:s', max(0, $now - self::OVERDUE_GRACE_SECONDS)));
        $schedulerAvailable = $this->schedulerHealth->isAvailable();

        return [
            'scheduler_available'   => $schedulerAvailable,
            'queue_status'          => $this->queueStatus($schedulerAvailable, (int) $summary['overdue_retry_count'], $summary),
            'queued_count'          => (int) $summary['queued_count'],
            'retry_scheduled_count' => (int) $summary['retry_scheduled_count'],
            'retrying_count'        => (int) $summary['retrying_count'],
            'failed_count'          => (int) $summary['failed_count'],
            'overdue_retry_count'   => (int) $summary['overdue_retry_count'],
            'next_retry_at'         => $summary['next_retry_at'],
            'recommended_actions'   => $this->recommendedActions($schedulerAvailable, (int) $summary['overdue_retry_count'], $summary),
        ];
    }

    /**
     * @param array{queued_count:int,retry_scheduled_count:int,retrying_count:int,failed_count:int,overdue_retry_count:int,next_retry_at:?string} $summary
     */
    private function queueStatus(bool $schedulerAvailable, int $overdueRetries, array $summary): string
    {
        if (! $schedulerAvailable || $overdueRetries > 0) {
            return 'attention';
        }

        if ((int) $summary['queued_count'] === 0 && (int) $summary['retry_scheduled_count'] === 0 && (int) $summary['retrying_count'] === 0) {
            return 'empty';
        }

        return 'healthy';
    }

    /**
     * @param array{queued_count:int,retry_scheduled_count:int,retrying_count:int,failed_count:int,overdue_retry_count:int,next_retry_at:?string} $summary
     * @return array<int,string>
     */
    private function recommendedActions(bool $schedulerAvailable, int $overdueRetries, array $summary): array
    {
        $actions = [];

        if (! $schedulerAvailable) {
            $actions[] = __('Load or repair Action Scheduler so Aculect Mail can enqueue background retries.', 'onesmtp');
        }

        if ($overdueRetries > 0) {
            $actions[] = __('Confirm WP-Cron or the Action Scheduler runner is processing jobs, then review recent message logs for safe resend decisions.', 'onesmtp');
        }

        if ($actions === [] && ((int) $summary['queued_count'] > 0 || (int) $summary['retry_scheduled_count'] > 0 || (int) $summary['retrying_count'] > 0)) {
            $actions[] = __('Retry processing is available. Monitor the queue and only intervene if counts stop moving.', 'onesmtp');
        }

        if ($actions === []) {
            $actions[] = __('No queued or retrying messages need administrator action.', 'onesmtp');
        }

        return $actions;
    }
}
