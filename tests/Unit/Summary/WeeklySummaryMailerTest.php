<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Summary;

use OneSMTP\Summary\WeeklySummaryMailer;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class WeeklySummaryMailerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_cron_events'] = [];
        $GLOBALS['onesmtp_test_mail'] = [];
    }

    public function test_sync_schedule_adds_and_clears_weekly_event_based_on_settings(): void
    {
        update_option('onesmtp_settings', [
            'weekly_summary' => [
                'enabled' => true,
                'email_recipients' => ['ops@example.test'],
            ],
        ], false);

        $mailer = new WeeklySummaryMailer();
        $mailer->syncSchedule();

        self::assertArrayHasKey(WeeklySummaryMailer::HOOK, $GLOBALS['onesmtp_test_cron_events']);
        self::assertSame('onesmtp_weekly', $GLOBALS['onesmtp_test_cron_events'][WeeklySummaryMailer::HOOK]['recurrence']);

        update_option('onesmtp_settings', [
            'weekly_summary' => [
                'enabled' => false,
                'email_recipients' => ['ops@example.test'],
            ],
        ], false);

        $mailer->syncSchedule();

        self::assertArrayNotHasKey(WeeklySummaryMailer::HOOK, $GLOBALS['onesmtp_test_cron_events']);
    }

    public function test_disabled_or_recipientless_summary_does_not_send_email(): void
    {
        update_option('onesmtp_settings', [
            'weekly_summary' => [
                'enabled' => true,
                'email_recipients' => [],
            ],
        ], false);

        self::assertFalse((new WeeklySummaryMailer())->sendScheduledSummary());
        self::assertSame([], $GLOBALS['onesmtp_test_mail']);
    }

    public function test_scheduled_summary_uses_aggregate_counts_without_sensitive_log_payloads(): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - 604800);
        $GLOBALS['wpdb']->dashboardActivityRowsBySince[$since] = [
            'sent_count' => 14,
            'failed_count' => 3,
            'retry_count' => 5,
            'payload_json' => '{"message":"secret body","api_key":"secret-key"}',
        ];
        $GLOBALS['wpdb']->dashboardFailoverCountsBySince[$since] = 2;
        $GLOBALS['wpdb']->dashboardPendingRow = [
            'queued_count' => 1,
            'retry_scheduled_count' => 2,
            'retrying_count' => 0,
            'payload_json' => '{"to":"private@example.test"}',
        ];
        $GLOBALS['wpdb']->dashboardProviderAttemptRowsBySince[$since] = [
            [
                'provider_id' => 10,
                'provider_name' => 'Primary SMTP api_key=secret-key',
                'adapter_type' => 'smtp',
                'sent_count' => 14,
                'failed_count' => 3,
                'retry_count' => 5,
            ],
        ];
        $GLOBALS['wpdb']->dashboardProviderFailoverRowsBySince[$since] = [
            [
                'provider_id' => 10,
                'provider_name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'failover_count' => 2,
            ],
        ];
        update_option('onesmtp_settings', [
            'weekly_summary' => [
                'enabled' => true,
                'email_recipients' => ['ops@example.test'],
            ],
        ], false);

        self::assertTrue((new WeeklySummaryMailer())->sendScheduledSummary());

        self::assertCount(1, $GLOBALS['onesmtp_test_mail']);
        $mail = $GLOBALS['onesmtp_test_mail'][0];
        $body = (string) $mail['message'];

        self::assertSame(['ops@example.test'], $mail['to']);
        self::assertStringContainsString('Sent attempts: 14', $body);
        self::assertStringContainsString('Failed attempts: 3', $body);
        self::assertStringContainsString('Retry attempts: 5', $body);
        self::assertStringContainsString('Provider failovers: 2', $body);
        self::assertStringContainsString('Primary SMTP api_key=[REDACTED]', $body);
        self::assertStringNotContainsString('secret-key', $body);
        self::assertStringNotContainsString('secret body', $body);
        self::assertStringNotContainsString('private@example.test', $body);
        self::assertStringNotContainsString('payload_json', $body);
    }

    public function test_empty_activity_window_still_sends_clear_empty_summary(): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - 604800);
        $GLOBALS['wpdb']->dashboardActivityRowsBySince[$since] = [
            'sent_count' => 0,
            'failed_count' => 0,
            'retry_count' => 0,
        ];
        $GLOBALS['wpdb']->dashboardFailoverCountsBySince[$since] = 0;
        $GLOBALS['wpdb']->dashboardPendingRow = [
            'queued_count' => 0,
            'retry_scheduled_count' => 0,
            'retrying_count' => 0,
        ];
        update_option('onesmtp_settings', [
            'weekly_summary' => [
                'enabled' => true,
                'email_recipients' => ['ops@example.test'],
            ],
        ], false);

        self::assertTrue((new WeeklySummaryMailer())->sendScheduledSummary());

        $body = (string) $GLOBALS['onesmtp_test_mail'][0]['message'];
        self::assertStringContainsString('No provider activity was logged in this window.', $body);
        self::assertStringContainsString('No delivery activity was logged in the last 7 days.', $body);
    }

    public function test_provider_rows_are_bounded_for_long_content(): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - 604800);
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = [
                'provider_id' => $i,
                'provider_name' => 'Provider ' . $i,
                'adapter_type' => 'smtp',
                'sent_count' => $i,
                'failed_count' => 0,
                'retry_count' => 0,
            ];
        }

        $GLOBALS['wpdb']->dashboardActivityRowsBySince[$since] = [
            'sent_count' => 78,
            'failed_count' => 0,
            'retry_count' => 0,
        ];
        $GLOBALS['wpdb']->dashboardProviderAttemptRowsBySince[$since] = $rows;
        update_option('onesmtp_settings', [
            'weekly_summary' => [
                'enabled' => true,
                'email_recipients' => ['ops@example.test'],
            ],
        ], false);

        self::assertTrue((new WeeklySummaryMailer())->sendScheduledSummary());

        $body = (string) $GLOBALS['onesmtp_test_mail'][0]['message'];
        self::assertStringContainsString('Provider 10', $body);
        self::assertStringNotContainsString('Provider 2', $body);
        self::assertStringContainsString('Additional provider rows were omitted', $body);
    }
}
