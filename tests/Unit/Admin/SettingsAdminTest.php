<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\SettingsAdmin;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $GLOBALS['pagenow'] = 'admin.php';
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_current_user_can'] = true;
        unset($GLOBALS['onesmtp_test_redirect']);
    }

    public function test_render_outputs_saved_values_without_exposing_unescaped_content(): void
    {
        update_option('onesmtp_settings', [
            'sender_identity' => [
                'from_email' => 'sender@example.test',
                'from_name' => 'Sender <script>alert(1)</script>',
                'reply_to' => ['reply@example.test'],
                'bcc' => ['audit@example.test'],
            ],
            'rate_limits' => [
                'per_minute' => 10,
                'per_hour' => 100,
                'per_day' => 500,
            ],
            'failure_alerts' => [
                'email_enabled' => true,
                'email_recipients' => ['ops@example.test'],
                'webhook_enabled' => true,
                'webhook_url' => 'https://hooks.example.test/' . str_repeat('long-path-', 20),
                'throttle_seconds' => 1200,
            ],
        ], false);

        $admin = new SettingsAdmin();

        ob_start();
        $admin->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('sender@example.test', $output);
        self::assertStringContainsString('Sender alert(1)', $output);
        self::assertStringNotContainsString('<script>', $output);
        self::assertStringContainsString('reply@example.test', $output);
        self::assertStringContainsString('audit@example.test', $output);
        self::assertStringContainsString('Save sender identity', $output);
        self::assertStringContainsString('Delivery rate limits', $output);
        self::assertStringContainsString('value="10"', $output);
        self::assertStringContainsString('value="100"', $output);
        self::assertStringContainsString('value="500"', $output);
        self::assertStringContainsString('Failure alerts', $output);
        self::assertStringContainsString('ops@example.test', $output);
        self::assertStringContainsString('https://hooks.example.test/long-path-', $output);
        self::assertStringContainsString('maxlength="2048"', $output);
        self::assertStringContainsString('value="1200"', $output);
        self::assertStringNotContainsString('Failure alerts are disabled', $output);
    }

    public function test_handle_request_saves_valid_sender_identity(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_settings_action' => 'save_sender_identity',
            'onesmtp_settings_nonce' => 'test-nonce',
            'from_email' => ' sender@example.test ',
            'from_name' => 'Sender',
            'reply_to' => "reply@example.test\nsecond@example.test",
            'bcc' => 'audit@example.test',
            'force_from_email' => '1',
            'force_reply_to' => '1',
        ];

        $admin = new SettingsAdmin();

        try {
            $admin->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP settings admin redirected.', $e->getMessage());
        }

        $settings = get_option('onesmtp_settings', []);
        self::assertSame('sender@example.test', $settings['sender_identity']['from_email']);
        self::assertSame(['reply@example.test', 'second@example.test'], $settings['sender_identity']['reply_to']);
        self::assertTrue($settings['sender_identity']['force_from_email']);
        self::assertTrue($settings['sender_identity']['force_reply_to']);
        self::assertStringContainsString('onesmtp_settings_status=saved', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_handle_request_saves_rate_limits(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_settings_action' => 'save_rate_limits',
            'onesmtp_settings_nonce' => 'test-nonce',
            'rate_limit_per_minute' => '5',
            'rate_limit_per_hour' => '60',
            'rate_limit_per_day' => '700',
        ];

        $admin = new SettingsAdmin();

        try {
            $admin->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP settings admin redirected.', $e->getMessage());
        }

        $settings = get_option('onesmtp_settings', []);
        self::assertSame(5, $settings['rate_limits']['per_minute']);
        self::assertSame(60, $settings['rate_limits']['per_hour']);
        self::assertSame(700, $settings['rate_limits']['per_day']);
        self::assertStringContainsString('onesmtp_settings_status=rate_limits_saved', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_render_shows_disabled_failure_alert_state_for_empty_configuration(): void
    {
        $admin = new SettingsAdmin();

        ob_start();
        $admin->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Failure alerts are disabled', $output);
        self::assertStringContainsString('name="failure_alert_email_enabled"', $output);
        self::assertStringContainsString('name="failure_alert_webhook_enabled"', $output);
        self::assertStringContainsString('Save failure alerts', $output);
    }

    public function test_handle_request_saves_failure_alert_settings(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_settings_action' => 'save_failure_alerts',
            'onesmtp_settings_nonce' => 'test-nonce',
            'failure_alert_email_enabled' => '1',
            'failure_alert_email_recipients' => "ops@example.test\nops2@example.test",
            'failure_alert_webhook_enabled' => '1',
            'failure_alert_webhook_url' => 'https://hooks.example.test/onesmtp',
            'failure_alert_throttle_seconds' => '1800',
        ];

        $admin = new SettingsAdmin();

        try {
            $admin->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP settings admin redirected.', $e->getMessage());
        }

        $settings = get_option('onesmtp_settings', []);
        self::assertTrue($settings['failure_alerts']['email_enabled']);
        self::assertSame(['ops@example.test', 'ops2@example.test'], $settings['failure_alerts']['email_recipients']);
        self::assertTrue($settings['failure_alerts']['webhook_enabled']);
        self::assertSame('https://hooks.example.test/onesmtp', $settings['failure_alerts']['webhook_url']);
        self::assertSame(1800, $settings['failure_alerts']['throttle_seconds']);
        self::assertStringContainsString('onesmtp_settings_status=failure_alerts_saved', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_invalid_failure_alert_webhook_is_rejected_without_saving(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_settings_action' => 'save_failure_alerts',
            'onesmtp_settings_nonce' => 'test-nonce',
            'failure_alert_webhook_enabled' => '1',
            'failure_alert_webhook_url' => 'http://hooks.example.test/plain',
        ];

        $admin = new SettingsAdmin();

        try {
            $admin->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP settings admin redirected.', $e->getMessage());
        }

        self::assertSame([], get_option('onesmtp_settings', []));
        self::assertStringContainsString('onesmtp_settings_status=invalid', (string) $GLOBALS['onesmtp_test_redirect']['location']);
        self::assertStringContainsString('webhook+URL+must+be+a+valid+HTTPS+URL', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_negative_rate_limits_are_saved_as_disabled(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_settings_action' => 'save_rate_limits',
            'onesmtp_settings_nonce' => 'test-nonce',
            'rate_limit_per_minute' => '-5',
            'rate_limit_per_hour' => '0',
            'rate_limit_per_day' => '-1',
        ];

        $admin = new SettingsAdmin();

        try {
            $admin->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP settings admin redirected.', $e->getMessage());
        }

        $settings = get_option('onesmtp_settings', []);
        self::assertSame(0, $settings['rate_limits']['per_minute']);
        self::assertSame(0, $settings['rate_limits']['per_hour']);
        self::assertSame(0, $settings['rate_limits']['per_day']);
    }

    public function test_invalid_email_is_rejected_without_saving(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_settings_action' => 'save_sender_identity',
            'onesmtp_settings_nonce' => 'test-nonce',
            'from_email' => 'not-an-email',
        ];

        $admin = new SettingsAdmin();

        try {
            $admin->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP settings admin redirected.', $e->getMessage());
        }

        self::assertSame([], get_option('onesmtp_settings', []));
        self::assertStringContainsString('onesmtp_settings_status=invalid', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_non_manager_cannot_save_sender_identity(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_settings_action' => 'save_sender_identity',
            'onesmtp_settings_nonce' => 'test-nonce',
            'from_email' => 'sender@example.test',
        ];
        $GLOBALS['onesmtp_test_current_user_can'] = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You are not allowed to manage OneSMTP settings.');

        (new SettingsAdmin())->handleRequest();
    }
}
