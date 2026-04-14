<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Queue;

use OneSMTP\Dispatch\DispatchPolicyInterface;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use PHPUnit\Framework\TestCase;

final class RetrySchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_actions'] = [];
        $GLOBALS['onesmtp_test_fired_actions'] = [];
        $GLOBALS['onesmtp_test_scheduled_actions'] = [];
        $GLOBALS['onesmtp_test_transients'] = [];
        $GLOBALS['onesmtp_test_action_scheduler_available'] = true;

        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            public array $inserts = [];
            public array $updates = [];

            public function insert(string $table, array $data, array $format): int
            {
                $this->insert_id++;
                $this->inserts[] = [
                    'table' => $table,
                    'data' => $data,
                    'format' => $format,
                ];

                return 1;
            }

            public function update(string $table, array $data, array $where, array $format, array $whereFormat): int
            {
                $this->updates[] = [
                    'table' => $table,
                    'data' => $data,
                    'where' => $where,
                    'format' => $format,
                    'where_format' => $whereFormat,
                ];

                return 1;
            }
        };
    }

    public function test_register_hooks_adds_retry_action_handler_for_two_args(): void
    {
        $scheduler = $this->buildScheduler();

        $scheduler->registerHooks();

        self::assertNotEmpty($GLOBALS['onesmtp_test_actions']);
        self::assertSame(RetryScheduler::ACTION_HOOK, $GLOBALS['onesmtp_test_actions'][0]['hook']);
        self::assertSame(2, $GLOBALS['onesmtp_test_actions'][0]['accepted_args']);
    }

    public function test_schedule_retry_uses_exponential_backoff_and_caps_at_one_hour(): void
    {
        $scheduler = $this->buildScheduler();
        $before = time();

        $scheduler->scheduleRetry(101, 1);
        $scheduler->scheduleRetry(101, 2);
        $scheduler->scheduleRetry(101, 8);

        $first = $this->findScheduled(RetryScheduler::ACTION_HOOK, [101, 1], 'onesmtp');
        $second = $this->findScheduled(RetryScheduler::ACTION_HOOK, [101, 2], 'onesmtp');
        $eighth = $this->findScheduled(RetryScheduler::ACTION_HOOK, [101, 8], 'onesmtp');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotNull($eighth);

        self::assertSame(60, $first['timestamp'] - $before);
        self::assertSame(120, $second['timestamp'] - $before);
        self::assertSame(3600, $eighth['timestamp'] - $before);
    }

    public function test_duplicate_attempt_prevention_uses_message_and_attempt_key(): void
    {
        $scheduler = $this->buildScheduler();

        $scheduler->scheduleRetry(999, 3);
        $countAfterFirst = count($GLOBALS['onesmtp_test_scheduled_actions']);

        $scheduler->scheduleRetry(999, 3);
        self::assertSame($countAfterFirst, count($GLOBALS['onesmtp_test_scheduled_actions']));

        $scheduler->scheduleRetry(999, 4);
        self::assertSame($countAfterFirst + 1, count($GLOBALS['onesmtp_test_scheduled_actions']));
    }

    public function test_scheduler_backend_missing_behavior_returns_run_at_without_queued_action(): void
    {
        $scheduler = $this->buildScheduler();
        $GLOBALS['onesmtp_test_action_scheduler_available'] = false;

        $runAt = $scheduler->scheduleRetry(44, 2);

        self::assertGreaterThan(time(), $runAt);
        self::assertNull($this->findScheduled(RetryScheduler::ACTION_HOOK, [44, 2], 'onesmtp'));
    }

    public function test_schedule_retry_marks_terminal_failure_when_attempt_exceeds_max(): void
    {
        $scheduler = $this->buildScheduler();

        $runAt = $scheduler->scheduleRetry(700, 7);

        self::assertSame(0, $runAt);
        self::assertCount(1, $GLOBALS['wpdb']->updates);
        self::assertSame('failed', $GLOBALS['wpdb']->updates[0]['data']['status']);
        self::assertSame(6, $GLOBALS['wpdb']->updates[0]['data']['current_attempt']);

        $terminalEvent = $this->findEventInsert('terminal_failure');
        self::assertNotNull($terminalEvent);

        $context = json_decode((string) $terminalEvent['data']['context_json'], true);
        self::assertSame('max_retries_boundary', $context['reason'] ?? null);
        self::assertSame(7, $context['attempt'] ?? null);
        self::assertSame(700, $terminalEvent['data']['message_id']);
    }

    public function test_retry_scheduled_event_contains_stable_lineage_context(): void
    {
        $scheduler = $this->buildScheduler();

        $runAt = $scheduler->scheduleRetry(55, 4);

        $event = $this->findEventInsert('retry_scheduled');
        self::assertNotNull($event);
        self::assertSame(55, $event['data']['message_id']);

        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame(4, $context['attempt'] ?? null);
        self::assertSame(gmdate('c', $runAt), $context['run_at'] ?? null);
    }

    private function buildScheduler(): RetryScheduler
    {
        $dispatch = $this->createMock(DispatchPolicyInterface::class);

        return new RetryScheduler(
            $dispatch,
            new MessageRepository(),
            new AttemptRepository(),
            new ProviderRepository(),
            new EventRepository()
        );
    }

    private function findScheduled(string $hook, array $args, string $group): ?array
    {
        $index = $hook . '|' . $group . '|' . md5((string) wp_json_encode($args));

        return $GLOBALS['onesmtp_test_scheduled_actions'][$index] ?? null;
    }

    private function findEventInsert(string $eventType): ?array
    {
        foreach ($GLOBALS['wpdb']->inserts as $insert) {
            if (
                str_ends_with($insert['table'], 'onesmtp_events')
                && (($insert['data']['event_type'] ?? '') === $eventType)
            ) {
                return $insert;
            }
        }

        return null;
    }
}
