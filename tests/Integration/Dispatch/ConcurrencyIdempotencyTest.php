<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Integration\Dispatch;

use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Dispatch\DispatchPolicyInterface;
use OneSMTP\Pipeline\SendPipeline;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class ConcurrencyIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_fired_actions'] = [];
        $GLOBALS['onesmtp_test_scheduled_actions'] = [];
        $GLOBALS['onesmtp_test_transients'] = [];
        $GLOBALS['onesmtp_test_action_scheduler_available'] = true;
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_lineage_context_contract_requires_stable_keys(): void
    {
        $context = [
            'message_id' => 1001,
            'attempt' => 3,
            'provider_id' => 22,
            'run_at' => '2026-04-14T12:00:00+00:00',
        ];

        self::assertArrayHasKey('message_id', $context);
        self::assertArrayHasKey('attempt', $context);
        self::assertArrayHasKey('provider_id', $context);
        self::assertArrayHasKey('run_at', $context);
        self::assertGreaterThan(0, $context['message_id']);
        self::assertGreaterThan(0, $context['attempt']);
    }

    public function test_parallel_workers_do_not_send_same_attempt_twice(): void
    {
        $GLOBALS['wpdb']->messageRowsById[321] = [
            'id' => 321,
            'status' => 'retry_scheduled',
            'message_uuid' => 'msg-321',
            'payload_json' => json_encode([
                'to' => ['qa@example.com'],
                'subject' => 'retry test',
                'message' => 'hello',
            ]),
        ];

        set_transient('retry_lock_321_3', 1, 120);

        $scheduler = $this->buildRetryScheduler();
        $scheduler->processRetry(321, 3, 'msg-321');

        self::assertSame([], $GLOBALS['onesmtp_test_fired_actions']);
        self::assertSame([], $GLOBALS['wpdb']->inserts);
    }

    public function test_message_uuid_capture_updates_existing_message_without_duplicate_insert(): void
    {
        $GLOBALS['wpdb']->messageRowsByUuid['msg-existing'] = [
            'id' => 777,
            'message_uuid' => 'msg-existing',
            'payload_json' => '{}',
        ];

        $pipeline = $this->buildSendPipeline();
        $captured = $pipeline->captureMessage([
            'to' => ['qa@example.com'],
            'subject' => 'accepted once',
            'message' => 'hello',
            'headers' => ['X-OneSMTP-Message-ID: msg-existing'],
        ]);

        self::assertSame('accepted once', $captured['subject']);
        self::assertSame([], $GLOBALS['wpdb']->inserts);
        self::assertNotEmpty($GLOBALS['wpdb']->updates);
        self::assertSame(['id' => 777], $GLOBALS['wpdb']->updates[0]['where']);
    }

    public function test_manual_resend_provider_override_takes_precedence_over_retry_context(): void
    {
        $policy = new DefaultDispatchPolicy();

        $providerId = $policy->chooseNextProvider(321, 4, [
            'providers' => [
                ['id' => 100, 'is_active' => 1, 'priority' => 1],
                ['id' => 200, 'is_active' => 1, 'priority' => 2],
                ['id' => 300, 'is_active' => 1, 'priority' => 3],
            ],
            'last_provider_id' => 100,
            'consecutive_failures_for_last_provider' => 2,
            'forced_provider_id' => 300,
        ]);

        self::assertSame(300, $providerId);
    }

    private function buildRetryScheduler(): RetryScheduler
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

    private function buildSendPipeline(): SendPipeline
    {
        $dispatch = new DefaultDispatchPolicy();
        $messages = new MessageRepository();
        $attempts = new AttemptRepository();
        $providers = new ProviderRepository();
        $events = new EventRepository();
        $retryScheduler = new RetryScheduler($dispatch, $messages, $attempts, $providers, $events);
        $deliveryEngine = new DeliveryEngine($providers, $attempts, $dispatch);

        return new SendPipeline($messages, $attempts, $providers, $events, $retryScheduler, $deliveryEngine);
    }
}
