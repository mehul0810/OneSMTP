<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\MailConflictNotice;
use OneSMTP\Conflict\MailConflictDetectorInterface;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailConflictNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_actions'] = [];
        $GLOBALS['onesmtp_test_current_user_can'] = true;
        $GLOBALS['onesmtp_test_transients'] = [];
        $GLOBALS['onesmtp_test_redirect'] = null;
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_register_hooks_adds_notice_and_dismiss_handler(): void
    {
        $notice = new MailConflictNotice($this->detector(['plugins' => [], 'hooks' => []]));

        $notice->registerHooks();

        self::assertSame('admin_notices', $GLOBALS['onesmtp_test_actions'][0]['hook']);
        self::assertSame('admin_post_onesmtp_dismiss_mail_conflict_notice', $GLOBALS['onesmtp_test_actions'][1]['hook']);
    }

    public function test_render_is_silent_for_empty_state(): void
    {
        $notice = new MailConflictNotice($this->detector(['plugins' => [], 'hooks' => []]));

        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    public function test_render_outputs_conservative_warning_for_detected_conflicts(): void
    {
        $notice = new MailConflictNotice($this->detector([
            'plugins' => ['WP Mail SMTP'],
            'hooks' => ['phpmailer_init' => 2],
        ]));

        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('notice-warning', $output);
        self::assertStringContainsString('WP Mail SMTP', $output);
        self::assertStringContainsString('phpmailer_init (2)', $output);
        self::assertStringContainsString('will not disable or reorder third-party code automatically', $output);
        self::assertStringContainsString('Remind me later', $output);
    }

    public function test_render_is_silent_without_manage_capability(): void
    {
        $GLOBALS['onesmtp_test_current_user_can'] = false;
        $notice = new MailConflictNotice($this->detector([
            'plugins' => ['WP Mail SMTP'],
            'hooks' => ['phpmailer_init' => 1],
        ]));

        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    public function test_render_is_silent_when_notice_is_dismissed(): void
    {
        set_transient('onesmtp_mail_conflict_notice_dismissed_1', 1, DAY_IN_SECONDS);
        $notice = new MailConflictNotice($this->detector([
            'plugins' => ['WP Mail SMTP'],
            'hooks' => ['phpmailer_init' => 1],
        ]));

        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    public function test_dismiss_requires_manage_capability(): void
    {
        $GLOBALS['onesmtp_test_current_user_can'] = false;
        $notice = new MailConflictNotice($this->detector(['plugins' => [], 'hooks' => []]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission');

        $notice->dismiss();
    }

    public function test_dismiss_sets_transient_redirects_and_logs_acknowledgement(): void
    {
        $notice = new MailConflictNotice($this->detector([
            'plugins' => ['WP Mail SMTP'],
            'hooks' => ['phpmailer_init' => 2],
        ]));

        try {
            $notice->dismiss();
            self::fail('Expected redirect exit in test runtime.');
        } catch (RuntimeException $e) {
            self::assertSame('Aculect Mail conflict notice dismissed.', $e->getMessage());
        }

        self::assertSame(1, get_transient('onesmtp_mail_conflict_notice_dismissed_1'));
        self::assertSame('audit_alert_acknowledged', $GLOBALS['wpdb']->inserts[0]['data']['event_type']);
        $json = (string) $GLOBALS['wpdb']->inserts[0]['data']['context_json'];
        self::assertStringContainsString('"plugin_count":1', $json);
        self::assertStringContainsString('"hook_count":1', $json);
        self::assertStringContainsString('options-general.php?page=onesmtp', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    /**
     * @param array{plugins:list<string>,hooks:array<string,int>} $result
     */
    private function detector(array $result): MailConflictDetectorInterface
    {
        return new class($result) implements MailConflictDetectorInterface {
            /**
             * @param array{plugins:list<string>,hooks:array<string,int>} $result
             */
            public function __construct(private array $result)
            {
            }

            /**
             * @return array{plugins:list<string>,hooks:array<string,int>}
             */
            public function detect(): array
            {
                return $this->result;
            }
        };
    }
}
