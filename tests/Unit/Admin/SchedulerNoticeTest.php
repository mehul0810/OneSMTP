<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\SchedulerNotice;
use OneSMTP\Queue\ActionSchedulerHealth;
use PHPUnit\Framework\TestCase;

final class SchedulerNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_actions'] = [];
        $GLOBALS['onesmtp_test_current_user_can'] = true;
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
}
