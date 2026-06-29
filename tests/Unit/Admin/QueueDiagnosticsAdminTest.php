<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\QueueDiagnosticsAdmin;
use OneSMTP\Diagnostics\DiagnosticReportGenerator;
use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class QueueDiagnosticsAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_current_user_can'], $GLOBALS['onesmtp_test_wp_die'], $GLOBALS['onesmtp_test_nonce_valid']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_current_user_can'], $GLOBALS['onesmtp_test_wp_die'], $GLOBALS['onesmtp_test_nonce_valid']);
        $_GET = [];
        $_POST = [];

        parent::tearDown();
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
        self::assertStringContainsString('Privacy-safe diagnostic report', $html);
        self::assertStringContainsString('Download diagnostic report', $html);
        self::assertStringContainsString('Report preview', $html);
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

    public function test_download_report_requires_manage_capability(): void
    {
        $_GET = [
            'onesmtp_diagnostic_action' => 'download_report',
            'onesmtp_diagnostic_nonce' => 'test-nonce',
        ];
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            'manage_onesmtp' => false,
            'manage_options' => false,
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to download OneSMTP diagnostics.');

        $this->admin(true)->handleRequest();
    }

    public function test_download_report_requires_valid_nonce(): void
    {
        $_GET = [
            'onesmtp_diagnostic_action' => 'download_report',
            'onesmtp_diagnostic_nonce' => 'expired',
        ];
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            'manage_onesmtp' => true,
        ];
        $GLOBALS['onesmtp_test_nonce_valid'] = false;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The OneSMTP diagnostic report link has expired.');

        $this->admin(true)->handleRequest();
    }

    public function test_download_report_outputs_sanitized_json_for_authorized_admin(): void
    {
        $_GET = [
            'onesmtp_diagnostic_action' => 'download_report',
            'onesmtp_diagnostic_nonce' => 'test-nonce',
        ];
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            'manage_onesmtp' => true,
        ];
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 9,
                'slug' => 'smtp_primary',
                'name' => 'SMTP password=hunter2',
                'adapter_type' => 'smtp',
                'priority' => 1,
                'weight' => 1,
                'is_active' => 1,
                'circuit_state' => 'closed',
                'circuit_until' => null,
                'config_json' => wp_json_encode(['password' => 'hunter2', 'host' => 'smtp.example.test']),
            ],
        ];

        ob_start();
        try {
            $this->admin(true)->handleRequest();
            self::fail('Expected diagnostic report download exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('OneSMTP diagnostic report downloaded.', $exception->getMessage());
        }
        $json = (string) ob_get_clean();

        self::assertStringContainsString('"schema_version": 1', $json);
        self::assertStringContainsString('"adapter_type": "smtp"', $json);
        self::assertStringContainsString('"config_values": "[excluded]"', $json);
        self::assertStringNotContainsString('hunter2', $json);
        self::assertStringNotContainsString('smtp.example.test', $json);
        self::assertStringNotContainsString('"config_json":', $json);
    }

    private function render(bool $schedulerAvailable): string
    {
        ob_start();
        $this->admin($schedulerAvailable)->render();

        return (string) ob_get_clean();
    }

    private function admin(bool $schedulerAvailable): QueueDiagnosticsAdmin
    {
        $queue = new QueueDiagnostics($this->health($schedulerAvailable), new MessageRepository(), static fn (): int => 1782302400);

        return new QueueDiagnosticsAdmin(
            $queue,
            new DiagnosticReportGenerator(new ProviderRepository(), $queue, new AttemptRepository(), null, static fn (): int => 1782302400)
        );
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
