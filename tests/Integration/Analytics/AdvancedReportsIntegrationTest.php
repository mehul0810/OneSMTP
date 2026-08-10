<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Integration\Analytics;

use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\MetricsRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class AdvancedReportsIntegrationTest extends TestCase
{
    private const SINCE = '2026-06-23 12:00:00';
    private const UNTIL = '2026-06-30 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_report_contract_tracks_message_and_attempt_log_records(): void
    {
        $messages = new MessageRepository();
        $attempts = new AttemptRepository();
        $messageId = $messages->create([
            'to' => ['recipient@example.test'],
            'subject' => 'Invoice token=fixture-secret',
            'message' => 'Private body must not enter a report.',
        ], 6, 'integration-message-39');
        $attemptId = $attempts->add([
            'message_id' => $messageId,
            'attempt_no' => 1,
            'provider_id' => 7,
            'result' => 'fail',
            'failure_category' => 'timeout',
            'error_message' => 'token=fixture-secret',
        ]);

        self::assertSame(1, $messageId);
        self::assertSame(2, $attemptId);
        self::assertSame('Invoice token=fixture-secret', $GLOBALS['wpdb']->inserts[0]['data']['subject']);

        $key = self::SINCE . '|' . self::UNTIL;
        $GLOBALS['wpdb']->advancedProviderRowsByWindow[ $key ] = [
			[
				'provider_id' => 7,
				'provider_name' => 'Fixture SMTP',
				'adapter_type' => 'smtp',
				'sent_count' => 0,
				'failed_count' => 1,
				'retry_count' => 0,
				'attempt_count' => 1,
				'avg_latency_ms' => null,
			],
		];
        $GLOBALS['wpdb']->advancedStatusRowsByWindow[ $key ] = [
			[
				'status' => 'failed',
				'status_count' => 1,
			],
		];
        $GLOBALS['wpdb']->advancedSubjectRowsByWindow[ $key ] = [
			[
				'subject' => 'Invoice token=fixture-secret',
				'subject_count' => 1,
			],
		];
        $GLOBALS['wpdb']->advancedTrendRowsByWindow[ $key ] = [
			[
				'period' => '2026-06-24',
				'status' => 'failed',
				'status_count' => 1,
			],
		];
        $GLOBALS['wpdb']->advancedFailureRowsByWindow[ $key ] = [
			[
				'failure_category' => 'timeout',
				'failure_count' => 1,
				'last_seen_at' => '2026-06-24 12:00:00',
			],
		];

        $report = (new MetricsRepository())->getAdvancedReport(self::SINCE, self::UNTIL);

        self::assertFalse($report['error']);
        self::assertSame(1, $report['providers'][0]['failed_count']);
        self::assertSame('failed', $report['statuses'][0]['status']);
        self::assertSame('Invoice token=fixture-secret', $report['subjects'][0]['subject']);
        self::assertSame('timeout', $report['failure_categories'][0]['category']);
        self::assertStringNotContainsString('Private body', wp_json_encode($report));
        self::assertStringNotContainsString('recipient@example.test', wp_json_encode($report));
    }
}
