<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\SchedulerNotice;
use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class SchedulerNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_actions'] = [];
        $GLOBALS['onesmtp_test_current_user_can'] = true;
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->activeProviders = [$this->activeProviderRow()];
        $GLOBALS['wpdb']->queueDiagnosticRow = $this->emptyQueueRow();
        unset($GLOBALS['onesmtp_test_object_cache']);
    }

    public function test_register_hooks_adds_admin_notice(): void
    {
        $notice = new SchedulerNotice($this->health(false));

        $notice->registerHooks();

        self::assertSame('admin_notices', $GLOBALS['onesmtp_test_actions'][0]['hook']);
    }

    public function test_render_outputs_notice_when_scheduler_is_unavailable(): void
    {
        $notice = new SchedulerNotice($this->health(false));

        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('notice-error', $output);
        self::assertStringContainsString('retry scheduling is unavailable', $output);
        self::assertStringContainsString('onesmtp-diagnostics', $output);
    }

    public function test_render_outputs_notice_when_no_active_provider_exists(): void
    {
        $GLOBALS['wpdb']->activeProviders = [];
        $notice = new SchedulerNotice($this->health(true));

        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('notice-error', $output);
        self::assertStringContainsString('no active email provider', $output);
        self::assertStringContainsString('onesmtp-providers', $output);
    }

    public function test_render_outputs_notice_when_queue_processing_appears_blocked(): void
    {
        $GLOBALS['wpdb']->queueDiagnosticRow = [
            'queued_count' => 0,
            'retry_scheduled_count' => 5,
            'retrying_count' => 1,
            'failed_count' => 2,
            'overdue_retry_count' => 4,
            'next_retry_at' => '2026-06-24 11:30:00',
            'payload_json' => '{"message":"private body","token":"secret-token"}',
        ];

        $notice = new SchedulerNotice($this->health(true));

        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('notice-warning', $output);
        self::assertStringContainsString('overdue retry jobs', $output);
        self::assertStringContainsString('onesmtp-diagnostics', $output);
        self::assertStringNotContainsString('private body', $output);
        self::assertStringNotContainsString('secret-token', $output);
    }

    public function test_render_is_silent_when_scheduler_is_available(): void
    {
        $notice = new SchedulerNotice($this->health(true));

        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    public function test_render_is_silent_without_manage_capability(): void
    {
        $GLOBALS['onesmtp_test_current_user_can'] = false;
        $notice = new SchedulerNotice($this->health(false));

        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
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

    /**
     * @return array<string,mixed>
     */
    private function activeProviderRow(): array
    {
        return [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 10,
            'weight' => 1,
            'is_active' => 1,
            'config_json' => wp_json_encode(['host' => 'smtp.example.test']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyQueueRow(): array
    {
        return [
            'queued_count' => 0,
            'retry_scheduled_count' => 0,
            'retrying_count' => 0,
            'failed_count' => 0,
            'overdue_retry_count' => 0,
            'next_retry_at' => null,
        ];
    }
}
