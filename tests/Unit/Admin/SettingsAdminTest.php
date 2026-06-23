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
