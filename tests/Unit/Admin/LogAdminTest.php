<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\LogAdmin;
use OneSMTP\Core\Capabilities;
use OneSMTP\Logging\AttachmentLogSanitizer;
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
        $GLOBALS['onesmtp_test_options'] = [
            'admin_email' => [
                'value' => 'owner@example.test',
                'autoload' => true,
            ],
        ];
        unset($GLOBALS['onesmtp_test_wp_die']);
        unset($GLOBALS['onesmtp_test_redirect']);
        unset($GLOBALS['onesmtp_test_nonce_valid']);
        unset($GLOBALS['onesmtp_test_mail']);
        unset($GLOBALS['onesmtp_test_mail_result']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_wp_die'], $GLOBALS['onesmtp_test_redirect'], $GLOBALS['onesmtp_test_object_cache'], $GLOBALS['onesmtp_test_nonce_valid'], $GLOBALS['onesmtp_test_mail'], $GLOBALS['onesmtp_test_mail_result'], $GLOBALS['onesmtp_test_options']);
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
        $this->expectExceptionMessage('You do not have permission to view Aculect Mail logs.');

        $this->renderLogs();

        self::assertSame(403, $GLOBALS['onesmtp_test_wp_die']['args']['response'] ?? null);
    }

    public function test_render_empty_log_list(): void
    {
        $html = $this->renderLogs();

        self::assertStringContainsString('Recent messages', $html);
        self::assertStringContainsString('No email activity yet', $html);
        self::assertStringContainsString('data-onesmtp-dataviews="delivery-messages"', $html);
    }

    public function test_render_queue_management_shows_pending_status_and_switchovers(): void
    {
        $GLOBALS['wpdb']->queueDiagnosticRow = [
            'queued_count' => 1,
            'retry_scheduled_count' => 1,
            'retrying_count' => 0,
            'failed_count' => 0,
            'overdue_retry_count' => 0,
            'next_retry_at' => '2026-06-23 10:30:00',
        ];
        $GLOBALS['wpdb']->queueMessageRows = [
            [
                'id' => 14,
                'message_uuid' => 'queue-14',
                'payload_json' => wp_json_encode(['to' => ['recipient@example.test']]),
                'status' => 'retry_scheduled',
                'selected_provider_id' => 3,
                'current_attempt' => 2,
                'max_attempts' => 6,
                'queue_attempt_count' => 2,
                'switch_count' => 1,
                'next_retry_at' => '2026-06-23 10:30:00',
            ],
        ];

        $html = $this->renderLogs();

        self::assertStringContainsString('Email queue', $html);
        self::assertStringContainsString('queue-14', $html);
        self::assertStringContainsString('retry scheduled', $html);
        self::assertStringContainsString('>1</td>', $html);
        self::assertStringContainsString('The queue is clear', $this->renderEmptyQueue());
    }

    public function test_queue_retry_now_action_uses_scheduler_callback(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::RESEND_EMAILS] = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_log_action' => 'queue_retry_now',
            'onesmtp_log_nonce' => 'test-nonce',
            'onesmtp_message_id' => '14',
        ];
        $GLOBALS['wpdb']->messageRowsById[14] = [
            'id' => 14,
            'message_uuid' => 'queue-14',
            'payload_json' => wp_json_encode(['to' => ['recipient@example.test']]),
            'status' => 'retry_scheduled',
            'current_attempt' => 2,
            'max_attempts' => 6,
        ];
        $called = [];
        $admin = new LogAdmin(
            new MessageRepository(),
            new AttemptRepository(),
            new ProviderRepository(),
            null,
            null,
            null,
            null,
            static function (int $messageId) use (&$called): bool {
                $called[] = $messageId;

                return true;
            }
        );

        try {
            $admin->handleRequest();
            self::fail('Expected queue redirect.');
        } catch (RuntimeException $exception) {
            self::assertSame('Aculect Mail queue admin redirected.', $exception->getMessage());
        }

        self::assertSame([14], $called);
        self::assertStringContainsString('onesmtp_queue_status=queued', (string) $GLOBALS['onesmtp_test_redirect']['location']);
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
                        'attachments' => ['/private/tmp/secret-contract.pdf'],
                        'onesmtp_source' => [
                            'type' => 'plugin',
                            'name' => 'Contact Forms',
                            'slug' => 'contact-forms',
                            'origin' => 'detected',
                            'metadata' => ['file' => '/private/path/wp-content/plugins/contact-forms/mail.php'],
                        ],
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
        self::assertStringContainsString('Plugin: Contact Forms', $html);
        self::assertStringContainsString('2 recipients across example.com, example.org', $html);
        self::assertStringNotContainsString('first@example.com', $html);
        self::assertStringNotContainsString('second@example.org', $html);
        self::assertStringNotContainsString('Sensitive subject', $html);
        self::assertStringNotContainsString('Secret body payload', $html);
        self::assertStringNotContainsString('raw-token', $html);
        self::assertStringNotContainsString('raw-secret', $html);
        self::assertStringNotContainsString('/private/path', $html);
        self::assertStringNotContainsString('/private/tmp', $html);
        self::assertStringNotContainsString('secret-contract.pdf', $html);
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
        self::assertStringContainsString('No messages match these filters', $this->renderFilteredEmptyLogs());
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
            'onesmtp_source' => wp_json_encode(['type' => 'theme', 'name' => 'Storefront Child']),
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
        self::assertStringContainsString('Unknown source', $html);
        self::assertStringContainsString('2 / 6', $html);
        self::assertStringContainsString('category=timeout provider_failed: password=[REDACTED] token=[REDACTED]', $html);
        self::assertStringContainsString('...', $html);
        self::assertStringContainsString('retry', $html);
        self::assertStringContainsString('sent', $html);
        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringNotContainsString('abc123', $html);
        self::assertStringNotContainsString('person@example.net', $html);
    }

    public function test_render_detail_shows_attachment_metadata_without_raw_paths(): void
    {
        $_GET['onesmtp_message_id'] = '77';
        $GLOBALS['wpdb']->messageRowsById[77] = [
            'id' => 77,
            'message_uuid' => 'lineage-77',
            'payload_json' => wp_json_encode([
                'to' => 'person@example.net',
                'attachments' => ['/private/tmp/legacy-raw.pdf'],
                AttachmentLogSanitizer::PAYLOAD_KEY => [
                    'enabled' => true,
                    'count' => 2,
                    'truncated' => false,
                    'items' => [
                        [
                            'filename' => 'invoice.pdf',
                            'extension' => 'pdf',
                            'size_bytes' => 2048,
                            'mime_type' => '',
                        ],
                        [
                            'filename' => 'customer.csv',
                            'extension' => 'csv',
                            'size_bytes' => null,
                            'mime_type' => '',
                        ],
                    ],
                ],
            ]),
            'status' => 'sent',
            'selected_provider_id' => 0,
            'current_attempt' => 1,
            'max_attempts' => 6,
            'created_at' => '2026-06-23 10:00:00',
            'updated_at' => '2026-06-23 10:01:00',
        ];
        $GLOBALS['wpdb']->recentMessageRows = [$GLOBALS['wpdb']->messageRowsById[77] + ['attempt_count' => 1]];

        $html = $this->renderLogs();

        self::assertStringContainsString('2 attachments: invoice.pdf, customer.csv', $html);
        self::assertStringContainsString('Attachment metadata', $html);
        self::assertStringContainsString('invoice.pdf', $html);
        self::assertStringContainsString('2.0 KB', $html);
        self::assertStringContainsString('Unknown', $html);
        self::assertStringNotContainsString('/private/tmp', $html);
        self::assertStringNotContainsString('legacy-raw.pdf', $html);
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

    public function test_render_list_exposes_bulk_resend_only_for_failed_messages(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::RESEND_EMAILS] = true;
        $GLOBALS['wpdb']->recentMessageRows = [
            [
                'id' => 31,
                'message_uuid' => 'lineage-31',
                'payload_json' => wp_json_encode(['to' => 'person@example.net']),
                'status' => 'failed',
                'selected_provider_id' => 0,
                'current_attempt' => 2,
                'max_attempts' => 6,
                'attempt_count' => 2,
                'created_at' => '2026-06-23 10:00:00',
                'updated_at' => '2026-06-23 10:01:00',
            ],
            [
                'id' => 32,
                'message_uuid' => 'lineage-32',
                'payload_json' => wp_json_encode(['to' => 'person@example.org']),
                'status' => 'sent',
                'selected_provider_id' => 0,
                'current_attempt' => 1,
                'max_attempts' => 6,
                'attempt_count' => 1,
                'created_at' => '2026-06-23 10:02:00',
                'updated_at' => '2026-06-23 10:03:00',
            ],
        ];

        $html = $this->renderLogs();
        $bulkForm = $this->htmlBetween($html, '<form method="post" class="onesmtp-bulk-resend-form">', '</form>');

        self::assertStringContainsString('Resend selected failed messages', $bulkForm);
        self::assertStringContainsString('name="onesmtp_message_ids[]" value="31"', $bulkForm);
        self::assertStringNotContainsString('name="onesmtp_message_ids[]" value="32"', $bulkForm);
        self::assertStringContainsString('Only failed messages can be selected for bulk resend.', $bulkForm);
        self::assertStringNotContainsString('person@example.net', $html);
        self::assertStringNotContainsString('person@example.org', $html);
    }

    public function test_render_detail_shows_forward_form_to_safe_admin_address(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::RESEND_EMAILS] = true;
        $_GET['onesmtp_message_id'] = '99';
        $GLOBALS['wpdb']->messageRowsById[99] = [
            'id' => 99,
            'message_uuid' => 'lineage-99',
            'payload_json' => wp_json_encode(['to' => 'person@example.net', 'subject' => 'Secret subject']),
            'status' => 'failed',
            'selected_provider_id' => 0,
            'current_attempt' => 2,
            'max_attempts' => 6,
        ];
        $GLOBALS['wpdb']->recentMessageRows = [$GLOBALS['wpdb']->messageRowsById[99] + ['attempt_count' => 2]];

        $html = $this->renderLogs();

        self::assertStringContainsString('Forward safe summary', $html);
        self::assertStringContainsString('owner@example.test', $html);
        self::assertStringContainsString('name="onesmtp_log_action" value="forward_summary"', $html);
        self::assertStringNotContainsString('Secret subject', $html);
        self::assertStringNotContainsString('person@example.net', $html);
    }

    public function test_render_detail_shows_safe_source_attribution_without_sensitive_metadata(): void
    {
        $_GET['onesmtp_message_id'] = '88';
        $GLOBALS['wpdb']->messageRowsById[88] = [
            'id' => 88,
            'message_uuid' => 'lineage-88',
            'payload_json' => wp_json_encode(
                [
                    'to' => 'person@example.net',
                    'onesmtp_source' => [
                        'type' => 'theme',
                        'name' => str_repeat('Long Theme Name ', 12),
                        'slug' => 'child-theme',
                        'origin' => 'detected',
                        'metadata' => [
                            'file' => '/srv/site/wp-content/themes/child-theme/functions.php',
                            'recipient' => 'person@example.net',
                            'token' => 'secret-token',
                        ],
                    ],
                ]
            ),
            'status' => 'sent',
            'selected_provider_id' => 0,
            'current_attempt' => 1,
            'max_attempts' => 6,
            'created_at' => '2026-06-23 10:00:00',
            'updated_at' => '2026-06-23 10:01:00',
        ];
        $GLOBALS['wpdb']->recentMessageRows = [$GLOBALS['wpdb']->messageRowsById[88] + ['attempt_count' => 1]];

        $html = $this->renderLogs();

        self::assertStringContainsString('Theme: Long Theme Name Long Theme Name Long Theme Name Long Theme Name Long Theme Na...', $html);
        self::assertStringContainsString('1 recipients across example.net', $html);
        self::assertStringNotContainsString('/srv/site', $html);
        self::assertStringNotContainsString('person@example.net', $html);
        self::assertStringNotContainsString('secret-token', $html);
    }

    public function test_render_list_hides_unsupported_source_labels(): void
    {
        $GLOBALS['wpdb']->recentMessageRows = [
            [
                'id' => 12,
                'message_uuid' => 'lineage-12',
                'payload_json' => wp_json_encode(
                    [
                        'to' => ['person@example.test'],
                        'onesmtp_source' => [
                            'type' => 'custom-mailer',
                            'name' => 'Private integration /srv/site/private.php',
                            'slug' => 'private-integration',
                        ],
                    ]
                ),
                'status' => 'sent',
                'selected_provider_id' => 0,
                'current_attempt' => 1,
                'max_attempts' => 6,
                'attempt_count' => 1,
                'created_at' => '2026-06-23 10:00:00',
                'updated_at' => '2026-06-23 10:01:00',
            ],
        ];

        $html = $this->renderLogs();

        self::assertStringContainsString('Unknown source', $html);
        self::assertStringNotContainsString('Private integration', $html);
        self::assertStringNotContainsString('/srv/site', $html);
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
        $this->expectExceptionMessage('You do not have permission to resend Aculect Mail emails.');

        (new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository()))->handleRequest();
    }

    public function test_bulk_resend_requires_resend_capability(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_log_action' => 'bulk_resend',
            'onesmtp_log_nonce' => 'test-nonce',
            'onesmtp_message_ids' => ['41'],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to bulk resend Aculect Mail emails.');

        (new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository()))->handleRequest();
    }

    public function test_bulk_resend_requires_valid_nonce(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::RESEND_EMAILS] = true;
        $GLOBALS['onesmtp_test_nonce_valid'] = false;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_log_action' => 'bulk_resend',
            'onesmtp_log_nonce' => 'test-nonce',
            'onesmtp_message_ids' => ['41'],
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
            self::fail('Expected nonce denial.');
        } catch (RuntimeException $exception) {
            self::assertSame('Invalid nonce.', $exception->getMessage());
        }

        self::assertFalse($called);
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
            self::assertStringContainsString('You do not have permission to export Aculect Mail logs.', $exception->getMessage());
        }

        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::VIEW_LOGS] = true;
        $GLOBALS['onesmtp_test_nonce_valid'] = false;

        try {
            (new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository()))->handleRequest();
            self::fail('Expected nonce denial.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('The Aculect Mail log export link has expired.', $exception->getMessage());
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
                        AttachmentLogSanitizer::PAYLOAD_KEY => [
                            'enabled' => true,
                            'count' => 1,
                            'truncated' => false,
                            'items' => [
                                [
                                    'filename' => 'report.pdf',
                                    'extension' => 'pdf',
                                    'size_bytes' => 1024,
                                    'mime_type' => '',
                                ],
                            ],
                        ],
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
            self::assertSame('Aculect Mail log CSV exported.', $exception->getMessage());
        }
        $csv = (string) ob_get_clean();

        self::assertStringContainsString('message_id,lineage_uuid,status,provider,attempt_count,max_attempts,attachment_summary,recipient_summary,next_retry_at,created_at,updated_at', $csv);
        self::assertStringContainsString('lineage-10', $csv);
        self::assertStringContainsString('Primary SMTP (smtp)', $csv);
        self::assertStringContainsString('1 attachment: report.pdf', $csv);
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
            self::assertSame('Aculect Mail log admin redirected.', $exception->getMessage());
        }

        self::assertSame([99, 7], $called);
        self::assertStringContainsString('onesmtp_resend_status=resent', (string) $GLOBALS['onesmtp_test_redirect']['location']);
        self::assertStringContainsString('onesmtp_message_id=99', (string) $GLOBALS['onesmtp_test_redirect']['location']);
        self::assertSame('audit_manual_resend', $GLOBALS['wpdb']->inserts[0]['data']['event_type']);

        $context = json_decode((string) $GLOBALS['wpdb']->inserts[0]['data']['context_json'], true);

        self::assertSame('resent', $context['status'] ?? null);
        self::assertTrue($context['provider_override'] ?? false);
    }

    public function test_bulk_resend_selected_failed_messages_invokes_pipeline_and_skips_non_failed(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::RESEND_EMAILS] = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_log_action' => 'bulk_resend',
            'onesmtp_log_nonce' => 'test-nonce',
            'onesmtp_message_ids' => ['41', '42', '43', '41'],
        ];
        $GLOBALS['wpdb']->messageRowsById[41] = [
            'id' => 41,
            'message_uuid' => 'lineage-41',
            'payload_json' => wp_json_encode(['to' => 'person@example.net']),
            'status' => 'failed',
        ];
        $GLOBALS['wpdb']->messageRowsById[42] = [
            'id' => 42,
            'message_uuid' => 'lineage-42',
            'payload_json' => wp_json_encode(['to' => 'person@example.org']),
            'status' => 'sent',
        ];
        $GLOBALS['wpdb']->messageRowsById[43] = [
            'id' => 43,
            'message_uuid' => 'lineage-43',
            'payload_json' => wp_json_encode(['to' => 'person@example.com']),
            'status' => 'failed',
        ];
        $called = [];

        $admin = new LogAdmin(
            new MessageRepository(),
            new AttemptRepository(),
            new ProviderRepository(),
            null,
            static function (int $messageId, ?int $providerId) use (&$called): bool {
                $called[] = [$messageId, $providerId];

                return $messageId === 41;
            }
        );

        try {
            $admin->handleRequest();
            self::fail('Expected redirect exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Aculect Mail log admin redirected.', $exception->getMessage());
        }

        self::assertSame([[41, null], [43, null]], $called);
        self::assertStringContainsString('onesmtp_bulk_resend_status=partial', (string) $GLOBALS['onesmtp_test_redirect']['location']);
        self::assertStringContainsString('onesmtp_bulk_resent=1', (string) $GLOBALS['onesmtp_test_redirect']['location']);
        self::assertStringContainsString('onesmtp_bulk_failed=2', (string) $GLOBALS['onesmtp_test_redirect']['location']);
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
            self::assertSame('Aculect Mail log admin redirected.', $exception->getMessage());
        }

        self::assertFalse($called);
        self::assertStringContainsString('onesmtp_resend_status=ineligible_provider', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_forward_action_requires_resend_capability(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_log_action' => 'forward_summary',
            'onesmtp_log_nonce' => 'test-nonce',
            'onesmtp_message_id' => '99',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to forward Aculect Mail log summaries.');

        (new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository()))->handleRequest();
    }

    public function test_forward_action_sends_safe_summary_without_raw_payload_or_secret_data(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'][Capabilities::RESEND_EMAILS] = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_log_action' => 'forward_summary',
            'onesmtp_log_nonce' => 'test-nonce',
            'onesmtp_message_id' => '55',
        ];
        $GLOBALS['wpdb']->messageRowsById[55] = [
            'id' => 55,
            'message_uuid' => 'lineage-55',
            'payload_json' => wp_json_encode(
                [
                    'to' => ['first@example.com', 'second@example.org'],
                    'subject' => 'Confidential subject',
                    'message' => 'Very private message body',
                    'headers' => ['Authorization: Bearer raw-token'],
                    'attachments' => ['/private/tmp/customer-contract.pdf'],
                    'onesmtp_source' => [
                        'type' => 'plugin',
                        'name' => 'Contact Forms',
                        'slug' => 'contact-forms',
                        'metadata' => ['file' => '/srv/private/plugin.php', 'token' => 'source-secret'],
                    ],
                    AttachmentLogSanitizer::PAYLOAD_KEY => [
                        'enabled' => true,
                        'count' => 1,
                        'truncated' => false,
                        'items' => [
                            [
                                'filename' => 'contract.pdf',
                                'extension' => 'pdf',
                                'size_bytes' => 1024,
                                'mime_type' => '',
                            ],
                        ],
                    ],
                ]
            ),
            'status' => 'failed',
            'selected_provider_id' => 5,
            'current_attempt' => 2,
            'max_attempts' => 6,
            'next_retry_at' => null,
            'created_at' => '2026-06-23 10:00:00',
            'updated_at' => '2026-06-23 10:05:00',
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
        $GLOBALS['wpdb']->attemptHistoryByMessage[55] = [
            [
                'id' => 7,
                'message_id' => 55,
                'attempt_no' => 2,
                'provider_id' => 5,
                'trigger_type' => 'manual_resend',
                'result' => 'fail',
                'error_code' => 'provider_failed',
                'error_message' => 'token=abc123 ' . str_repeat('timeout ', 80),
                'failure_category' => 'timeout',
                'provider_message_id' => 'provider-secret-id',
            ],
        ];

        try {
            (new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository()))->handleRequest();
            self::fail('Expected redirect exception.');
        } catch (RuntimeException $exception) {
            self::assertSame('Aculect Mail log admin redirected.', $exception->getMessage());
        }

        self::assertCount(1, $GLOBALS['onesmtp_test_mail']);
        $mail = $GLOBALS['onesmtp_test_mail'][0];
        $body = (string) $mail['message'];

        self::assertSame('owner@example.test', $mail['to']);
        self::assertStringContainsString('Aculect Mail safe log summary #55', (string) $mail['subject']);
        self::assertStringContainsString('Lineage UUID: lineage-55', $body);
        self::assertStringContainsString('Provider: Primary SMTP (smtp)', $body);
        self::assertStringContainsString('Source: Plugin: Contact Forms', $body);
        self::assertStringContainsString('Recipients: 2 recipients across example.com, example.org', $body);
        self::assertStringContainsString('Attachments: 1 attachment: contract.pdf', $body);
        self::assertStringContainsString('Latest safe error context: category=timeout provider_failed: token=[REDACTED]', $body);
        self::assertStringContainsString('...', $body);
        self::assertStringNotContainsString('first@example.com', $body);
        self::assertStringNotContainsString('second@example.org', $body);
        self::assertStringNotContainsString('Confidential subject', $body);
        self::assertStringNotContainsString('Very private message body', $body);
        self::assertStringNotContainsString('raw-token', $body);
        self::assertStringNotContainsString('raw-secret', $body);
        self::assertStringNotContainsString('/private/tmp', $body);
        self::assertStringNotContainsString('/srv/private', $body);
        self::assertStringNotContainsString('source-secret', $body);
        self::assertStringContainsString('onesmtp_forward_status=forwarded', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_render_missing_detail_row(): void
    {
        $_GET['onesmtp_message_id'] = '404';

        $html = $this->renderLogs();

        self::assertStringContainsString('The requested log entry was not found.', $html);
        self::assertStringContainsString('No email activity yet', $html);
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

    private function renderEmptyQueue(): string
    {
        $originalRows = $GLOBALS['wpdb']->queueMessageRows;
        $GLOBALS['wpdb']->queueMessageRows = [];

        try {
            return $this->renderLogs();
        } finally {
            $GLOBALS['wpdb']->queueMessageRows = $originalRows;
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
