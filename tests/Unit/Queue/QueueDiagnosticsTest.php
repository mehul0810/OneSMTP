<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Queue;

use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class QueueDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_empty_queue_reports_healthy_empty_state(): void
    {
        $GLOBALS['wpdb']->queueDiagnosticRow = [
            'queued_count' => 0,
            'retry_scheduled_count' => 0,
            'retrying_count' => 0,
            'failed_count' => 0,
            'overdue_retry_count' => 0,
            'next_retry_at' => null,
        ];

        $snapshot = $this->diagnostics(true)->snapshot();

        self::assertTrue($snapshot['scheduler_available']);
        self::assertSame('empty', $snapshot['queue_status']);
        self::assertSame(0, $snapshot['overdue_retry_count']);
        self::assertSame(['No queued or retrying messages need administrator action.'], $snapshot['recommended_actions']);
    }

    public function test_scheduler_unavailable_reports_attention_and_action(): void
    {
        $GLOBALS['wpdb']->queueDiagnosticRow = [
            'queued_count' => 1,
            'retry_scheduled_count' => 1,
            'retrying_count' => 0,
            'failed_count' => 0,
            'overdue_retry_count' => 0,
            'next_retry_at' => '2026-06-24 12:10:00',
        ];

        $snapshot = $this->diagnostics(false)->snapshot();

        self::assertFalse($snapshot['scheduler_available']);
        self::assertSame('attention', $snapshot['queue_status']);
        self::assertSame(1, $snapshot['retry_scheduled_count']);
        self::assertStringContainsString('Action Scheduler', implode(' ', $snapshot['recommended_actions']));
    }

    public function test_overdue_retries_are_counted_without_payload_data(): void
    {
        $GLOBALS['wpdb']->queueDiagnosticRow = [
            'queued_count' => 0,
            'retry_scheduled_count' => 3,
            'retrying_count' => 1,
            'failed_count' => 2,
            'overdue_retry_count' => 2,
            'next_retry_at' => '2026-06-24 11:50:00',
            'payload_json' => '{"message":"secret body","api_key":"abc123"}',
        ];

        $snapshot = $this->diagnostics(true)->snapshot();

        self::assertSame('attention', $snapshot['queue_status']);
        self::assertSame(2, $snapshot['overdue_retry_count']);
        self::assertSame(3, $snapshot['retry_scheduled_count']);
        self::assertArrayNotHasKey('payload_json', $snapshot);
        self::assertStringNotContainsString('secret body', wp_json_encode($snapshot));
        self::assertStringNotContainsString('abc123', wp_json_encode($snapshot));
    }

    private function diagnostics(bool $schedulerAvailable): QueueDiagnostics
    {
        return new QueueDiagnostics($this->health($schedulerAvailable), new MessageRepository(), static fn (): int => 1782302400);
    }

    private function health(bool $available): ActionSchedulerHealth
    {
        return new class($available) extends ActionSchedulerHealth {
            private bool $available;

            public function __construct(bool $available)
            {
                $this->available = $available;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }
}
