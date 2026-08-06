<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Queue;

use OneSMTP\Conflict\MailDeliveryOwnership;
use OneSMTP\Dispatch\DispatchPolicyInterface;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
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
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_register_hooks_adds_retry_action_handler_for_three_args(): void
    {
        $scheduler = $this->buildScheduler();

        $scheduler->registerHooks();

        self::assertNotEmpty($GLOBALS['onesmtp_test_actions']);
        self::assertSame(RetryScheduler::ACTION_HOOK, $GLOBALS['onesmtp_test_actions'][0]['hook']);
        self::assertSame(3, $GLOBALS['onesmtp_test_actions'][0]['accepted_args']);
        self::assertSame(RetryScheduler::BACKGROUND_ACTION_HOOK, $GLOBALS['onesmtp_test_actions'][1]['hook']);
        self::assertSame(3, $GLOBALS['onesmtp_test_actions'][1]['accepted_args']);
    }

    public function test_schedule_retry_uses_exponential_backoff_and_caps_at_one_hour(): void
    {
        $scheduler = $this->buildScheduler();
        $before = time();

        $scheduler->scheduleRetry(101, 1, 'uuid-101');
        $scheduler->scheduleRetry(101, 2, 'uuid-101');
        $scheduler->scheduleRetry(101, 8, 'uuid-101');

        $first = $this->findScheduled(RetryScheduler::ACTION_HOOK, [101, 1, 'uuid-101'], 'onesmtp');
        $second = $this->findScheduled(RetryScheduler::ACTION_HOOK, [101, 2, 'uuid-101'], 'onesmtp');

        self::assertNotNull($first);
        self::assertNotNull($second);

        self::assertSame(60, $first['timestamp'] - $before);
        self::assertSame(120, $second['timestamp'] - $before);

        self::assertNull($this->findScheduled(RetryScheduler::ACTION_HOOK, [101, 8, 'uuid-101'], 'onesmtp'));
        $terminalEvent = $this->findEventInsert('terminal_failure');
        self::assertNotNull($terminalEvent);
    }

    public function test_duplicate_attempt_prevention_uses_message_attempt_and_uuid_key(): void
    {
        $scheduler = $this->buildScheduler();

        $scheduler->scheduleRetry(999, 3, 'uuid-a');
        $countAfterFirst = count($GLOBALS['onesmtp_test_scheduled_actions']);

        $scheduler->scheduleRetry(999, 3, 'uuid-a');
        self::assertSame($countAfterFirst, count($GLOBALS['onesmtp_test_scheduled_actions']));

        $scheduler->scheduleRetry(999, 3, 'uuid-b');
        self::assertSame($countAfterFirst, count($GLOBALS['onesmtp_test_scheduled_actions']));
    }

    public function test_scheduler_backend_missing_returns_null_and_logs_failure_event(): void
    {
        $scheduler = $this->buildScheduler();
        $GLOBALS['onesmtp_test_action_scheduler_available'] = false;

        $runAt = $scheduler->scheduleRetry(44, 2, 'uuid-44');

        self::assertNull($runAt);
        self::assertNull($this->findScheduled(RetryScheduler::ACTION_HOOK, [44, 2, 'uuid-44'], 'onesmtp'));
        self::assertNull($this->findEventInsert('retry_scheduled'));

        $event = $this->findEventInsert('retry_schedule_failed');
        self::assertNotNull($event);

        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame('scheduler_backend_unavailable', $context['reason'] ?? null);
        self::assertSame(2, $context['attempt'] ?? null);
    }

    public function test_schedule_background_send_uses_action_scheduler_without_payload_context(): void
    {
        $scheduler = $this->buildScheduler();

        $runAt = $scheduler->scheduleBackgroundSend(55, 1, 'uuid-55');

        self::assertIsInt($runAt);
        self::assertNotNull($this->findScheduled(RetryScheduler::BACKGROUND_ACTION_HOOK, [55, 1, 'uuid-55'], 'onesmtp'));

        $event = $this->findEventInsert('background_send_queued');
        self::assertNotNull($event);
        $contextJson = (string) $event['data']['context_json'];
        self::assertStringContainsString('run_at', $contextJson);
        self::assertStringNotContainsString('recipient@example.test', $contextJson);
        self::assertStringNotContainsString('payload_json', $contextJson);
    }

    public function test_background_scheduler_backend_missing_returns_null_and_logs_failure_event(): void
    {
        $scheduler = $this->buildScheduler();
        $GLOBALS['onesmtp_test_action_scheduler_available'] = false;

        $runAt = $scheduler->scheduleBackgroundSend(56, 1, 'uuid-56');

        self::assertNull($runAt);
        self::assertNull($this->findScheduled(RetryScheduler::BACKGROUND_ACTION_HOOK, [56, 1, 'uuid-56'], 'onesmtp'));

        $event = $this->findEventInsert('background_send_schedule_failed');
        self::assertNotNull($event);

        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame('scheduler_backend_unavailable', $context['reason'] ?? null);
        self::assertSame(1, $context['attempt'] ?? null);
    }

    public function test_schedule_retry_marks_terminal_failure_when_attempt_exceeds_max(): void
    {
        $scheduler = $this->buildScheduler();

        $runAt = $scheduler->scheduleRetry(700, 7, 'uuid-700');

        self::assertNull($runAt);
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

    public function test_schedule_retry_updates_message_state_after_successful_enqueue(): void
    {
        $scheduler = $this->buildScheduler();

        $runAt = $scheduler->scheduleRetry(45, 2, 'uuid-45');

        self::assertIsInt($runAt);
        self::assertNotEmpty($GLOBALS['wpdb']->updates);

        $update = $GLOBALS['wpdb']->updates[0];
        self::assertSame(['id' => 45], $update['where']);
        self::assertSame('retry_scheduled', $update['data']['status']);
        self::assertSame(2, $update['data']['current_attempt']);
        self::assertSame(gmdate('Y-m-d H:i:s', $runAt), $update['data']['next_retry_at']);
    }

    public function test_schedule_immediate_retry_requeues_a_scheduled_message_with_short_delay(): void
    {
        $GLOBALS['wpdb']->messageRowsById[46] = [
            'id' => 46,
            'message_uuid' => 'uuid-46',
            'payload_json' => wp_json_encode(['to' => ['recipient@example.test'], 'message' => 'safe']),
            'status' => 'retry_scheduled',
            'current_attempt' => 2,
            'max_attempts' => 6,
        ];

        $scheduler = $this->buildScheduler();
        $before = time();

        self::assertTrue($scheduler->scheduleImmediateRetry(46));

        $scheduled = $this->findScheduled(RetryScheduler::ACTION_HOOK, [46, 2, 'uuid-46'], 'onesmtp');
        self::assertNotNull($scheduled);
        self::assertGreaterThanOrEqual($before + 1, $scheduled['timestamp']);
        self::assertLessThanOrEqual(time() + 1, $scheduled['timestamp']);

        $event = $this->findEventInsert('queue_retry_now_requested');
        self::assertNotNull($event);
        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame(2, $context['attempt'] ?? null);
    }

    public function test_schedule_retry_does_not_enqueue_terminal_messages(): void
    {
        $GLOBALS['wpdb']->messageRowsById[88] = [
            'id' => 88,
            'status' => 'sent',
            'max_attempts' => 6,
        ];

        $scheduler = $this->buildScheduler();

        $runAt = $scheduler->scheduleRetry(88, 2, 'uuid-88');

        self::assertNull($runAt);
        self::assertSame([], $GLOBALS['onesmtp_test_scheduled_actions']);
        self::assertSame([], $GLOBALS['wpdb']->updates);

        $event = $this->findEventInsert('retry_not_scheduled');
        self::assertNotNull($event);

        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame('terminal_status', $context['reason'] ?? null);
    }

    public function test_process_retry_skips_missing_payload_without_dispatching(): void
    {
        $GLOBALS['wpdb']->messageRowsById[89] = [
            'id' => 89,
            'status' => 'retry_scheduled',
            'message_uuid' => 'uuid-89',
            'max_attempts' => 6,
            'payload_json' => '',
        ];

        $scheduler = $this->buildScheduler();
        $scheduler->processRetry(89, 2, 'uuid-89');

        self::assertSame([], $GLOBALS['onesmtp_test_fired_actions']);
        self::assertSame([], $GLOBALS['wpdb']->updates);

        $event = $this->findEventInsert('retry_skipped');
        self::assertNotNull($event);

        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame('payload_missing', $context['reason'] ?? null);
    }

    public function test_process_background_send_dispatches_safe_worker_action(): void
    {
        $GLOBALS['wpdb']->messageRowsById[90] = [
            'id' => 90,
            'status' => 'queued',
            'message_uuid' => 'uuid-90',
            'max_attempts' => 6,
            'payload_json' => wp_json_encode([
                'to' => ['recipient@example.test'],
                'subject' => 'Private subject',
                'message' => 'Private body',
            ]),
        ];

        $dispatch = $this->createMock(DispatchPolicyInterface::class);
        $dispatch->method('chooseNextProvider')->willReturn(12);
        $scheduler = new RetryScheduler(
            $dispatch,
            new MessageRepository(),
            new AttemptRepository(),
            new ProviderRepository(),
            new EventRepository()
        );

        $scheduler->processBackgroundSend(90, 1, 'uuid-90');

        self::assertNotEmpty($GLOBALS['onesmtp_test_fired_actions']);
        self::assertSame('onesmtp_background_send_attempt', $GLOBALS['onesmtp_test_fired_actions'][0]['hook']);
        self::assertSame(12, $GLOBALS['onesmtp_test_fired_actions'][0]['args'][4] ?? null);

        $runningUpdate = $GLOBALS['wpdb']->updates[0] ?? null;
        self::assertIsArray($runningUpdate);
        self::assertSame('retrying', $runningUpdate['data']['status']);
        self::assertSame(12, $runningUpdate['data']['selected_provider_id']);

        $event = $this->findEventInsert('background_send_dispatched');
        self::assertNotNull($event);
        $contextJson = (string) $event['data']['context_json'];
        self::assertStringNotContainsString('recipient@example.test', $contextJson);
        self::assertStringNotContainsString('Private body', $contextJson);
    }

    public function test_scheduled_work_remains_queued_when_suremail_owns_delivery(): void
    {
        $GLOBALS['wpdb']->messageRowsById[91] = [
            'id' => 91,
            'status' => 'retry_scheduled',
            'message_uuid' => 'uuid-91',
            'max_attempts' => 6,
            'payload_json' => wp_json_encode(['to' => ['recipient@example.test'], 'message' => 'Private body']),
        ];

        $dispatch = $this->createMock(DispatchPolicyInterface::class);
        $dispatch->expects(self::never())->method('chooseNextProvider');
        $scheduler = new RetryScheduler(
            $dispatch,
            new MessageRepository(),
            new AttemptRepository(),
            new ProviderRepository(),
            new EventRepository(),
            new MailDeliveryOwnership(MailDeliveryOwnership::SUREMAIL)
        );

        $scheduler->processRetry(91, 2, 'uuid-91');

        self::assertSame([], $GLOBALS['onesmtp_test_fired_actions']);
        self::assertSame([], $GLOBALS['wpdb']->updates);
        $event = $this->findEventInsert('delivery_paused');
        self::assertNotNull($event);
        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame('external_delivery_owner', $context['reason'] ?? null);
        self::assertSame('retry', $context['trigger'] ?? null);
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
