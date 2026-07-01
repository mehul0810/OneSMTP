<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Alerts;

use OneSMTP\Alerts\AlertEventRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class AlertEventRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_recent_alerts_include_acknowledgement_status_and_sanitized_context(): void
    {
        $GLOBALS['wpdb']->eventRows = [
            10 => [
                'id' => 10,
                'event_type' => 'terminal_failure',
                'actor_id' => 0,
                'message_id' => 25,
                'provider_id' => 7,
                'context_json' => wp_json_encode([
                    'reason' => 'missing_api_key',
                    'failure_category' => 'authentication',
                    'summary' => 'Failed with token=raw-secret-token',
                    'raw_context' => [
                        'api_key' => 'private-provider-key',
                    ],
                ]),
                'created_at' => '2026-07-01 10:00:00',
            ],
        ];
        $GLOBALS['wpdb']->eventAcknowledgementRows = [
            [
                'id' => 12,
                'actor_id' => 44,
                'context_json' => wp_json_encode(['alert_event_id' => 10]),
                'created_at' => '2026-07-01 10:05:00',
            ],
        ];

        $events = (new AlertEventRepository())->recent();

        self::assertCount(1, $events);
        self::assertSame('acknowledged', $events[0]['status']);
        self::assertSame(44, $events[0]['acknowledged_by']);
        self::assertSame('2026-07-01 10:05:00', $events[0]['acknowledged_at']);
        self::assertStringContainsString('[REDACTED]', (string) $events[0]['summary']);
        self::assertSame('[REDACTED]', $events[0]['context']['raw_context']['api_key']);

        $encoded = wp_json_encode($events);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('raw-secret-token', $encoded);
        self::assertStringNotContainsString('private-provider-key', $encoded);
    }

    public function test_acknowledge_persists_sanitized_audit_event_linked_to_alert_event(): void
    {
        $GLOBALS['wpdb']->eventRows = [
            15 => [
                'id' => 15,
                'event_type' => 'terminal_failure',
                'actor_id' => 0,
                'message_id' => 99,
                'provider_id' => 5,
                'context_json' => wp_json_encode([
                    'reason' => 'provider_error',
                    'authorization' => 'Bearer provider-token',
                ]),
                'created_at' => '2026-07-01 11:00:00',
            ],
        ];

        $acknowledgementId = (new AlertEventRepository())->acknowledge(15);

        self::assertSame(1, $acknowledgementId);
        self::assertCount(1, $GLOBALS['wpdb']->inserts);
        self::assertSame('audit_alert_acknowledged', $GLOBALS['wpdb']->inserts[0]['data']['event_type']);

        $context = json_decode( (string) $GLOBALS['wpdb']->inserts[0]['data']['context_json'], true);
        self::assertSame(15, $context['alert_event_id'] ?? null);
        self::assertSame('terminal_failure', $context['alert_event_type'] ?? null);
        self::assertSame('acknowledged', $context['alert_status'] ?? null);
        self::assertSame('[REDACTED]', $context['alert_context']['authorization'] ?? null);
        self::assertStringNotContainsString('provider-token', (string) $GLOBALS['wpdb']->inserts[0]['data']['context_json']);
    }
}
