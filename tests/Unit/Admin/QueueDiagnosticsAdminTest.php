<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\QueueDiagnosticsAdmin;
use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class QueueDiagnosticsAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_render_outputs_empty_healthy_queue_state(): void
    {
        $GLOBALS['wpdb']->queueDiagnosticRow = [
            'queued_count' => 0,
            'retry_scheduled_count' => 0,
            'retrying_count' => 0,
            'failed_count' => 0,
            'overdue_retry_count' => 0,
            'next_retry_at' => null,
        ];

        $html = $this->render(true);

        self::assertStringContainsString('OneSMTP has no queued or retrying messages.', $html);
        self::assertStringContainsString('Scheduler availability', $html);
        self::assertStringContainsString('Available', $html);
        self::assertStringContainsString('Overdue retries', $html);
    }

    public function test_render_outputs_unavailable_scheduler_and_overdue_recovery_actions(): void
    {
        $GLOBALS['wpdb']->queueDiagnosticRow = [
            'queued_count' => 0,
            'retry_scheduled_count' => 4,
            'retrying_count' => 0,
            'failed_count' => 1,
            'overdue_retry_count' => 3,
            'next_retry_at' => '2026-06-24 11:30:00',
            'payload_json' => '{"message":"customer body","token":"secret-token"}',
        ];

        $html = $this->render(false);

        self::assertStringContainsString('Unavailable', $html);
        self::assertStringContainsString('Overdue retries</th><td>3', $html);
        self::assertStringContainsString('Action Scheduler', $html);
        self::assertStringContainsString('WP-Cron', $html);
        self::assertStringNotContainsString('customer body', $html);
        self::assertStringNotContainsString('secret-token', $html);
    }

    private function render(bool $schedulerAvailable): string
    {
        $admin = new QueueDiagnosticsAdmin(new QueueDiagnostics($this->health($schedulerAvailable), new MessageRepository(), static fn (): int => 1782302400));

        ob_start();
        $admin->render();

        return (string) ob_get_clean();
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
