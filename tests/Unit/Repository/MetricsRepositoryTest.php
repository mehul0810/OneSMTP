<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Repository;

use OneSMTP\Repository\MetricsRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class MetricsRepositoryTest extends TestCase
{
    private const SINCE = '2026-06-23 12:00:00';
    private const UNTIL = '2026-06-30 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_activity_and_pending_summaries_are_aggregate_only(): void
    {
        $GLOBALS['wpdb']->dashboardActivityRowsBySince[self::SINCE] = [
            'sent_count' => 12,
            'failed_count' => 3,
            'retry_count' => 5,
            'payload_json' => '{"message":"secret body","api_key":"secret-key"}',
        ];
        $GLOBALS['wpdb']->dashboardFailoverCountsBySince[self::SINCE] = 2;
        $GLOBALS['wpdb']->dashboardPendingRow = [
            'queued_count' => 4,
            'retry_scheduled_count' => 6,
            'retrying_count' => 1,
            'payload_json' => '{"message":"pending body"}',
        ];

        $repository = new MetricsRepository();

        $activity = $repository->getActivityWindowSummary(self::SINCE);
        $pending = $repository->getPendingSummary();

        self::assertSame([
            'sent_count' => 12,
            'failed_count' => 3,
            'retry_count' => 5,
            'failover_count' => 2,
        ], $activity);
        self::assertSame([
            'queued_count' => 4,
            'retry_scheduled_count' => 6,
            'retrying_count' => 1,
            'total_pending_count' => 11,
        ], $pending);
        self::assertArrayNotHasKey('payload_json', $activity);
        self::assertArrayNotHasKey('payload_json', $pending);
    }

    public function test_provider_breakdown_merges_attempts_and_failover_events(): void
    {
        $GLOBALS['wpdb']->dashboardProviderAttemptRowsBySince[self::SINCE] = [
            [
                'provider_id' => 10,
                'provider_name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'sent_count' => 7,
                'failed_count' => 2,
                'retry_count' => 3,
                'avg_latency_ms' => '825.6',
            ],
            [
                'provider_id' => 20,
                'provider_name' => 'Backup Postmark',
                'adapter_type' => 'postmark',
                'sent_count' => 1,
                'failed_count' => 0,
                'retry_count' => 0,
            ],
        ];
        $GLOBALS['wpdb']->dashboardProviderFailoverRowsBySince[self::SINCE] = [
            [
                'provider_id' => 20,
                'provider_name' => 'Backup Postmark',
                'adapter_type' => 'postmark',
                'failover_count' => 4,
            ],
            [
                'provider_id' => 30,
                'provider_name' => 'Failover Only',
                'adapter_type' => 'sendgrid',
                'failover_count' => 2,
            ],
        ];

        $breakdown = (new MetricsRepository())->getProviderBreakdown(self::SINCE);

        self::assertSame(10, $breakdown[0]['provider_id']);
        self::assertSame(12, $breakdown[0]['total_activity']);
        self::assertSame(826, $breakdown[0]['avg_latency_ms']);
        self::assertSame(20, $breakdown[1]['provider_id']);
        self::assertSame(1, $breakdown[1]['sent_count']);
        self::assertSame(4, $breakdown[1]['failover_count']);
        self::assertSame(5, $breakdown[1]['total_activity']);
        self::assertSame(30, $breakdown[2]['provider_id']);
        self::assertSame(0, $breakdown[2]['sent_count']);
        self::assertNull($breakdown[2]['avg_latency_ms']);
        self::assertSame(2, $breakdown[2]['failover_count']);
    }

    public function test_provider_breakdown_counts_switches_away_from_failed_provider(): void
    {
        $GLOBALS['wpdb']->dashboardProviderAttemptRowsBySince[self::SINCE] = [
            [
                'provider_id' => 10,
                'provider_name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'sent_count' => 1,
                'failed_count' => 1,
                'retry_count' => 0,
            ],
        ];
        $GLOBALS['wpdb']->dashboardProviderEventRowsBySince[self::SINCE] = [
            ['context_json' => wp_json_encode(['from_provider_id' => 10, 'to_provider_id' => 20])],
            ['context_json' => wp_json_encode(['from_provider_id' => 10, 'to_provider_id' => 30])],
        ];
        $GLOBALS['wpdb']->activeProviders = [
            ['id' => 10, 'name' => 'Primary SMTP', 'adapter_type' => 'smtp'],
        ];

        $breakdown = (new MetricsRepository())->getProviderBreakdown(self::SINCE);

        self::assertSame(2, $breakdown[0]['switch_out_count']);
        self::assertSame(4, $breakdown[0]['total_activity']);
    }

    public function test_advanced_report_returns_bounded_log_slices_without_sensitive_columns(): void
    {
        $key = self::SINCE . '|' . self::UNTIL;
        $GLOBALS['wpdb']->advancedProviderRowsByWindow[$key] = [[
            'provider_id' => 10,
            'provider_name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'sent_count' => 8,
            'failed_count' => 2,
            'retry_count' => 1,
            'attempt_count' => 10,
            'avg_latency_ms' => '812.4',
            'payload_json' => '{"body":"secret"}',
        ]];
        $GLOBALS['wpdb']->advancedStatusRowsByWindow[$key] = [
            ['status' => 'sent', 'status_count' => 4],
            ['status' => 'failed', 'status_count' => 1],
        ];
        $GLOBALS['wpdb']->advancedSubjectRowsByWindow[$key] = [[
            'subject' => 'Invoice token=secret-value',
            'subject_count' => 3,
        ]];
        $GLOBALS['wpdb']->advancedTrendRowsByWindow[$key] = [[
            'period' => '2026-06-24',
            'status' => 'sent',
            'status_count' => 4,
        ]];
        $GLOBALS['wpdb']->advancedFailureRowsByWindow[$key] = [[
            'failure_category' => 'timeout',
            'failure_count' => 2,
            'last_seen_at' => '2026-06-29 10:00:00',
        ]];

        $report = (new MetricsRepository())->getAdvancedReport(self::SINCE, self::UNTIL, 20);

        self::assertFalse($report['error']);
        self::assertSame(10, $report['providers'][0]['attempt_count']);
        self::assertSame('sent', $report['statuses'][0]['status']);
        self::assertSame('Invoice token=secret-value', $report['subjects'][0]['subject']);
        self::assertSame('2026-06-24', $report['trend'][0]['period']);
        self::assertSame('timeout', $report['failure_categories'][0]['category']);
        self::assertArrayNotHasKey('payload_json', $report['providers'][0]);

        $queries = array_map(static fn (array $prepared): string => $prepared['query'], $GLOBALS['wpdb']->preparedQueries);
        $advancedQueries = array_values(array_filter($queries, static fn (string $query): bool => str_contains($query, 'created_at < %s')));
        self::assertCount(5, $advancedQueries);
        foreach ($advancedQueries as $query) {
            self::assertStringContainsString('LIMIT %d', $query);
            self::assertStringContainsString('created_at >= %s', $query);
        }
    }

    public function test_advanced_report_marks_database_error_without_fabricating_data(): void
    {
        $GLOBALS['wpdb']->failAdvancedQueries = true;

        $report = (new MetricsRepository())->getAdvancedReport(self::SINCE, self::UNTIL);

        self::assertTrue($report['error']);
        self::assertSame([], $report['providers']);
        self::assertSame([], $report['subjects']);
    }
}
