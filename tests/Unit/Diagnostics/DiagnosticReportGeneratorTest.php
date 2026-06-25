<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Diagnostics;

use OneSMTP\Diagnostics\DiagnosticReportGenerator;
use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class DiagnosticReportGeneratorTest extends TestCase
{
    private const NOW = 1782302400;
    private const SINCE = '2026-06-17 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
    }

    public function test_report_includes_safe_sections_and_excludes_sensitive_values(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 7,
                'slug' => 'primary_api',
                'name' => 'Primary API token=provider-token',
                'adapter_type' => 'sendgrid',
                'priority' => 10,
                'weight' => 2,
                'is_active' => 1,
                'circuit_state' => 'closed',
                'circuit_until' => null,
                'config_json' => wp_json_encode(
                    [
                        'api_key' => 'raw-api-key',
                        'webhook_url' => 'https://hooks.example.test/secret-token',
                        'safe_label' => 'Support mailbox',
                    ]
                ),
            ],
        ];
        $GLOBALS['wpdb']->queueDiagnosticRow = [
            'queued_count' => 1,
            'retry_scheduled_count' => 2,
            'retrying_count' => 0,
            'failed_count' => 3,
            'overdue_retry_count' => 0,
            'next_retry_at' => '2026-06-24 12:30:00',
        ];
        $GLOBALS['wpdb']->failureCategoryRowsBySince[self::SINCE] = [
            [
                'failure_category' => 'authentication',
                'failure_count' => 3,
                'last_seen_at' => '2026-06-24 12:00:00',
            ],
        ];

        $report = $this->generator(true)->generate();
        $encoded = (string) wp_json_encode($report);

        self::assertSame(1, $report['schema_version']);
        self::assertSame('2026-06-24T12:00:00Z', $report['generated_at']);
        self::assertSame('0.1.0', $report['plugin']['version']);
        self::assertSame(1, $report['plugin']['provider_count']);
        self::assertSame('sendgrid', $report['providers'][0]['adapter_type']);
        self::assertSame('[excluded]', $report['providers'][0]['config_values']);
        self::assertSame('healthy', $report['queue']['queue_status']);
        self::assertSame('authentication', $report['recent_failures']['categories'][0]['category']);
        self::assertSame('applied', $report['redaction']['status']);

        self::assertStringNotContainsString('provider-token', $encoded);
        self::assertStringNotContainsString('raw-api-key', $encoded);
        self::assertStringNotContainsString('secret-token', $encoded);
        self::assertStringNotContainsString('Support mailbox', $encoded);
        self::assertStringNotContainsString('"config_json":', $encoded);
        self::assertStringNotContainsString('https://hooks.example.test', $encoded);
    }

    public function test_report_handles_empty_provider_and_failure_states(): void
    {
        $GLOBALS['wpdb']->activeProviders = [];
        $GLOBALS['wpdb']->queueDiagnosticRow = [
            'queued_count' => 0,
            'retry_scheduled_count' => 0,
            'retrying_count' => 0,
            'failed_count' => 0,
            'overdue_retry_count' => 0,
            'next_retry_at' => null,
        ];

        $report = $this->generator(true)->generate();

        self::assertSame(0, $report['plugin']['provider_count']);
        self::assertSame([], $report['providers']);
        self::assertSame('empty', $report['queue']['queue_status']);
        self::assertSame([], $report['recent_failures']['categories']);
    }

    private function generator(bool $schedulerAvailable): DiagnosticReportGenerator
    {
        $queue = new QueueDiagnostics($this->health($schedulerAvailable), new MessageRepository(), static fn (): int => self::NOW);

        return new DiagnosticReportGenerator(new ProviderRepository(), $queue, new AttemptRepository(), null, static fn (): int => self::NOW);
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
