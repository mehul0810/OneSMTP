<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\LogAdmin;
use OneSMTP\Core\Capabilities;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LogAdminTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            Capabilities::VIEW_LOGS => true,
            'manage_options' => false,
        ];
        unset($GLOBALS['onesmtp_test_wp_die']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_wp_die']);
    }

    public function test_render_requires_log_view_capability(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            Capabilities::VIEW_LOGS => false,
            'manage_options' => false,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to view OneSMTP logs.');

        $this->renderLogs();

        self::assertSame(403, $GLOBALS['onesmtp_test_wp_die']['args']['response'] ?? null);
    }

    public function test_render_empty_log_list(): void
    {
        $html = $this->renderLogs();

        self::assertStringContainsString('Recent messages', $html);
        self::assertStringContainsString('No email log entries have been recorded yet.', $html);
    }

    public function test_render_list_uses_safe_recipient_metadata_without_payload_content(): void
    {
        $GLOBALS['wpdb']->recentMessageRows = [
            [
                'id' => 10,
                'message_uuid' => 'lineage-10',
                'payload_json' => wp_json_encode(
                    [
                        'to' => ['first@example.com', 'second@example.org'],
                        'subject' => 'Sensitive subject',
                        'message' => 'Secret body payload',
                        'headers' => ['Authorization: Bearer raw-token'],
                    ]
                ),
                'status' => 'sent',
                'selected_provider_id' => 5,
                'current_attempt' => 1,
                'max_attempts' => 6,
                'attempt_count' => 1,
                'created_at' => '2026-06-23 10:00:00',
                'updated_at' => '2026-06-23 10:01:00',
            ],
        ];
        $GLOBALS['wpdb']->providerRowsById[5] = [
            'id' => 5,
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 1,
            'weight' => 1,
            'is_active' => 1,
            'config_json' => wp_json_encode(['password' => 'raw-secret']),
        ];

        $html = $this->renderLogs();

        self::assertStringContainsString('lineage-10', $html);
        self::assertStringContainsString('sent', $html);
        self::assertStringContainsString('Primary SMTP (smtp)', $html);
        self::assertStringContainsString('2 recipients across example.com, example.org', $html);
        self::assertStringNotContainsString('first@example.com', $html);
        self::assertStringNotContainsString('second@example.org', $html);
        self::assertStringNotContainsString('Sensitive subject', $html);
        self::assertStringNotContainsString('Secret body payload', $html);
        self::assertStringNotContainsString('raw-token', $html);
        self::assertStringNotContainsString('raw-secret', $html);
    }

    public function test_render_detail_redacts_errors_and_shows_retry_lineage(): void
    {
        $_GET['onesmtp_message_id'] = '99';
        $longError = 'password=hunter2 token=abc123 ' . str_repeat('provider timeout ', 40);
        $GLOBALS['wpdb']->messageRowsById[99] = [
            'id' => 99,
            'message_uuid' => 'lineage-99',
            'payload_json' => wp_json_encode(['to' => 'person@example.net']),
            'status' => 'retry_scheduled',
            'selected_provider_id' => 7,
            'current_attempt' => 2,
            'max_attempts' => 6,
            'next_retry_at' => '2026-06-23 10:30:00',
            'created_at' => '2026-06-23 10:00:00',
            'updated_at' => '2026-06-23 10:02:00',
        ];
        $GLOBALS['wpdb']->recentMessageRows = [$GLOBALS['wpdb']->messageRowsById[99] + ['attempt_count' => 2]];
        $GLOBALS['wpdb']->attemptHistoryByMessage[99] = [
            [
                'id' => 1,
                'message_id' => 99,
                'attempt_no' => 1,
                'provider_id' => 7,
                'trigger_type' => 'initial',
                'result' => 'fail',
                'error_code' => 'provider_failed',
                'error_message' => $longError,
                'latency_ms' => 1200,
                'provider_message_id' => 'provider-secret-id-1234567890',
                'created_at' => '2026-06-23 10:01:00',
            ],
            [
                'id' => 2,
                'message_id' => 99,
                'attempt_no' => 2,
                'provider_id' => 8,
                'trigger_type' => 'retry',
                'result' => 'sent',
                'error_code' => '',
                'error_message' => '',
                'latency_ms' => 800,
                'provider_message_id' => 'accepted-2',
                'created_at' => '2026-06-23 10:02:00',
            ],
        ];

        $html = $this->renderLogs();

        self::assertStringContainsString('Message detail', $html);
        self::assertStringContainsString('lineage-99', $html);
        self::assertStringContainsString('retry scheduled', $html);
        self::assertStringContainsString('2 / 6', $html);
        self::assertStringContainsString('provider_failed: password=[REDACTED] token=[REDACTED]', $html);
        self::assertStringContainsString('...', $html);
        self::assertStringContainsString('retry', $html);
        self::assertStringContainsString('sent', $html);
        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringNotContainsString('abc123', $html);
        self::assertStringNotContainsString('person@example.net', $html);
    }

    public function test_render_missing_detail_row(): void
    {
        $_GET['onesmtp_message_id'] = '404';

        $html = $this->renderLogs();

        self::assertStringContainsString('The requested log entry was not found.', $html);
        self::assertStringContainsString('No email log entries have been recorded yet.', $html);
    }

    private function renderLogs(): string
    {
        $admin = new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository());

        ob_start();
        try {
            $admin->render();

            return (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw $throwable;
        }
    }
}
