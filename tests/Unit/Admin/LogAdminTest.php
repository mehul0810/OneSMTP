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
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_object_cache'] = [];
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            Capabilities::VIEW_LOGS => true,
            Capabilities::RESEND_EMAILS => false,
            'manage_options' => false,
        ];
        unset($GLOBALS['onesmtp_test_wp_die']);
        unset($GLOBALS['onesmtp_test_redirect']);
        unset($GLOBALS['onesmtp_test_nonce_valid']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_wp_die'], $GLOBALS['onesmtp_test_redirect'], $GLOBALS['onesmtp_test_object_cache'], $GLOBALS['onesmtp_test_nonce_valid']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
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

    public function test_render_list_applies_status_provider_date_and_safe_search_filters(): void
    {
        $recipientHash = str_repeat('a', 64);
        $_GET = [
            'status' => 'failed',
            'provider_id' => '7',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'recipient_hash' => $recipientHash,
            's' => 'lineage-42',
        ];
        $GLOBALS['wpdb']->recentMessageRows = [
            [
                'id' => 42,
                'message_uuid' => 'lineage-42',
                'payload_json' => wp_json_encode(['to' => ['person@example.test']]),
                'status' => 'failed',
                'selected_provider_id' => 7,
                'current_attempt' => 2,
                'max_attempts' => 6,
                'attempt_count' => 2,
                'created_at' => '2026-06-12 10:00:00',
                'updated_at' => '2026-06-12 10:03:00',
            ],
        ];
        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 1,
            'weight' => 1,
            'is_active' => 1,
        ];

        $html = $this->renderLogs();

        self::assertStringContainsString('lineage-42', $html);
        self::assertStringContainsString('No email log entries matched the current filters.', $this->renderFilteredEmptyLogs());
        self::assertIsArray($GLOBALS['wpdb']->lastPrepared);
        self::assertStringContainsString('m.status = %s', $GLOBALS['wpdb']->lastPrepared['query']);
        self::assertStringContainsString('m.selected_provider_id = %d', $GLOBALS['wpdb']->lastPrepared['query']);
        self::assertStringContainsString('m.created_at &gt;= %s', esc_html($GLOBALS['wpdb']->lastPrepared['query']));
        self::assertStringContainsString('m.created_at &lt;= %s', esc_html($GLOBALS['wpdb']->lastPrepared['query']));
        self::assertStringContainsString('m.recipients_hash = %s', $GLOBALS['wpdb']->lastPrepared['query']);
        self::assertStringContainsString('m.message_uuid LIKE %s', $GLOBALS['wpdb']->lastPrepared['query']);
        self::assertSame(
            ['failed', 7, '2026-06-01 00:00:00', '2026-06-30 23:59:59', $recipientHash, '%lineage-42%', 25, 0],
            $GLOBALS['wpdb']->lastPrepared['args']
        );
    }

    public function test_render_list_search_supports_recipient_hash_and_pagination_offsets(): void
    {
        $hash = str_repeat('b', 64);
        $_GET = [
            's' => $hash,
            'onesmtp_log_page' => '2',
            'onesmtp_logs_per_page' => '25',
        ];
        $GLOBALS['wpdb']->filteredMessageCount = 60;
        $GLOBALS['wpdb']->recentMessageRows = [
            [
                'id' => 35,
                'message_uuid' => 'lineage-35',
                'payload_json' => wp_json_encode(['to' => ['person@example.test']]),
                'status' => 'sent',
                'selected_provider_id' => 0,
                'current_attempt' => 1,
                'max_attempts' => 6,
                'attempt_count' => 1,
                'created_at' => '2026-06-12 10:00:00',
                'updated_at' => '2026-06-12 10:01:00',
            ],
        ];

        $html = $this->renderLogs();

        self::assertStringContainsString('Page 2 of 3 (60 log entries)', $html);
        self::assertStringContainsString('Previous', $html);
        self::assertStringContainsString('Next', $html);
        self::assertIsArray($GLOBALS['wpdb']->lastPrepared);
        self::assertStringContainsString('m.recipients_hash = %s', $GLOBALS['wpdb']->lastPrepared['query']);
        self::assertSame(['%' . $hash . '%', $hash, 25, 25], $GLOBALS['wpdb']->lastPrepared['args']);
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
                'failure_category' => 'timeout',
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
        self::assertStringContainsString('category=timeout provider_failed: password=[REDACTED] token=[REDACTED]', $html);
        self::assertStringContainsString('...', $html);
        self::assertStringContainsString('retry', $html);
        self::assertStringContainsString('sent', $html);
        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringNotContainsString('abc123', $html);
        self::assertStringNotContainsString('person@example.net', $html);
    }

    public function test_render_detail_shows_resend_form_with_only_eligible_active_providers(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::RESEND_EMAILS] = true;
        $_GET['onesmtp_message_id'] = '99';
        $GLOBALS['wpdb']->messageRowsById[99] = [
            'id' => 99,
            'message_uuid' => 'lineage-99',
            'payload_json' => wp_json_encode(['to' => 'person@example.net', 'message' => 'Private body']),
            'status' => 'failed',
            'selected_provider_id' => 7,
            'current_attempt' => 2,
            'max_attempts' => 6,
        ];
        $GLOBALS['wpdb']->recentMessageRows = [$GLOBALS['wpdb']->messageRowsById[99] + ['attempt_count' => 2]];
        $GLOBALS['wpdb']->activeProviders = [
            ['id' => 7, 'name' => 'Primary SMTP', 'adapter_type' => 'smtp', 'is_active' => 1, 'priority' => 1, 'weight' => 1],
            ['id' => 8, 'name' => 'Inactive SMTP', 'adapter_type' => 'smtp', 'is_active' => 0, 'priority' => 2, 'weight' => 1],
            [
                'id' => 9,
                'name' => 'Open Circuit',
                'adapter_type' => 'smtp',
                'is_active' => 1,
                'priority' => 3,
                'weight' => 1,
                'circuit_state' => 'open',
                'circuit_until' => gmdate('Y-m-d H:i:s', time() + 300),
            ],
        ];

        $html = $this->renderLogs();
        $resendFormHtml = $this->htmlBetween($html, '<h4>Manual resend</h4>', '<h4>Attempt lineage</h4>');

        self::assertStringContainsString('Manual resend', $html);
        self::assertStringContainsString('name="onesmtp_log_action" value="resend"', $resendFormHtml);
        self::assertStringContainsString('Use normal provider selection', $resendFormHtml);
        self::assertStringContainsString('Primary SMTP (smtp)', $resendFormHtml);
        self::assertStringNotContainsString('Inactive SMTP', $resendFormHtml);
        self::assertStringNotContainsString('Open Circuit', $resendFormHtml);
        self::assertStringNotContainsString('Private body', $html);
        self::assertStringNotContainsString('person@example.net', $html);
    }

    public function test_resend_action_requires_resend_capability(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_log_action' => 'resend',
            'onesmtp_log_nonce' => 'test-nonce',
            'onesmtp_message_id' => '99',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to resend OneSMTP emails.');

        (new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository()))->handleRequest();
    }

    public function test_csv_export_requires_view_capability_and_valid_nonce(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [
            'onesmtp_log_action' => 'export_csv',
            'onesmtp_log_export_nonce' => 'test-nonce',
        ];
        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::VIEW_LOGS] = false;

        try {
            (new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository()))->handleRequest();
            self::fail('Expected capability denial.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('You do not have permission to export OneSMTP logs.', $exception->getMessage());
        }

        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::VIEW_LOGS] = true;
        $GLOBALS['onesmtp_test_nonce_valid'] = false;

        try {
            (new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository()))->handleRequest();
            self::fail('Expected nonce denial.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('The OneSMTP log export link has expired.', $exception->getMessage());
        }
    }

    public function test_csv_export_includes_only_safe_log_fields(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [
            'onesmtp_log_action' => 'export_csv',
            'onesmtp_log_export_nonce' => 'test-nonce',
            'status' => 'sent',
        ];
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
                'next_retry_at' => null,
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

        ob_start();
        try {
            (new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository()))->handleRequest();
            self::fail('Expected CSV export exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('OneSMTP log CSV exported.', $exception->getMessage());
        }
        $csv = (string) ob_get_clean();

        self::assertStringContainsString('message_id,lineage_uuid,status,provider,attempt_count,max_attempts,recipient_summary,next_retry_at,created_at,updated_at', $csv);
        self::assertStringContainsString('lineage-10', $csv);
        self::assertStringContainsString('Primary SMTP (smtp)', $csv);
        self::assertStringContainsString('2 recipients across example.com, example.org', $csv);
        self::assertStringNotContainsString('first@example.com', $csv);
        self::assertStringNotContainsString('second@example.org', $csv);
        self::assertStringNotContainsString('Sensitive subject', $csv);
        self::assertStringNotContainsString('Secret body payload', $csv);
        self::assertStringNotContainsString('raw-token', $csv);
        self::assertStringNotContainsString('raw-secret', $csv);
        self::assertStringNotContainsString('payload_json', $csv);
    }

    public function test_resend_action_invokes_pipeline_with_eligible_provider_override(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::RESEND_EMAILS] = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_log_action' => 'resend',
            'onesmtp_log_nonce' => 'test-nonce',
            'onesmtp_message_id' => '99',
            'provider_id' => '7',
        ];
        $GLOBALS['wpdb']->messageRowsById[99] = [
            'id' => 99,
            'message_uuid' => 'lineage-99',
            'payload_json' => wp_json_encode(['to' => 'person@example.net']),
        ];
        $GLOBALS['wpdb']->activeProviders = [
            ['id' => 7, 'name' => 'Primary SMTP', 'adapter_type' => 'smtp', 'is_active' => 1, 'priority' => 1, 'weight' => 1],
        ];
        $called = [];

        $admin = new LogAdmin(
            new MessageRepository(),
            new AttemptRepository(),
            new ProviderRepository(),
            null,
            static function (int $messageId, ?int $providerId) use (&$called): bool {
                $called = [$messageId, $providerId];

                return true;
            }
        );

        try {
            $admin->handleRequest();
            self::fail('Expected redirect exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('OneSMTP log admin redirected.', $exception->getMessage());
        }

        self::assertSame([99, 7], $called);
        self::assertStringContainsString('onesmtp_resend_status=resent', (string) $GLOBALS['onesmtp_test_redirect']['location']);
        self::assertStringContainsString('onesmtp_message_id=99', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_resend_action_rejects_ineligible_provider_before_pipeline_call(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::RESEND_EMAILS] = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_log_action' => 'resend',
            'onesmtp_log_nonce' => 'test-nonce',
            'onesmtp_message_id' => '99',
            'provider_id' => '8',
        ];
        $GLOBALS['wpdb']->messageRowsById[99] = [
            'id' => 99,
            'message_uuid' => 'lineage-99',
            'payload_json' => wp_json_encode(['to' => 'person@example.net']),
        ];
        $GLOBALS['wpdb']->activeProviders = [
            ['id' => 7, 'name' => 'Primary SMTP', 'adapter_type' => 'smtp', 'is_active' => 1, 'priority' => 1, 'weight' => 1],
        ];
        $called = false;

        $admin = new LogAdmin(
            new MessageRepository(),
            new AttemptRepository(),
            new ProviderRepository(),
            null,
            static function () use (&$called): bool {
                $called = true;

                return true;
            }
        );

        try {
            $admin->handleRequest();
            self::fail('Expected redirect exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('OneSMTP log admin redirected.', $exception->getMessage());
        }

        self::assertFalse($called);
        self::assertStringContainsString('onesmtp_resend_status=ineligible_provider', (string) $GLOBALS['onesmtp_test_redirect']['location']);
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

    private function renderFilteredEmptyLogs(): string
    {
        $originalRows = $GLOBALS['wpdb']->recentMessageRows;
        $GLOBALS['wpdb']->recentMessageRows = [];

        try {
            return $this->renderLogs();
        } finally {
            $GLOBALS['wpdb']->recentMessageRows = $originalRows;
        }
    }

    private function htmlBetween(string $html, string $start, string $end): string
    {
        $startPosition = strpos($html, $start);
        $endPosition = strpos($html, $end);

        if ($startPosition === false || $endPosition === false || $endPosition <= $startPosition) {
            return $html;
        }

        return substr($html, $startPosition, $endPosition - $startPosition);
    }
}
