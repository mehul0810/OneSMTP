<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Alerts;

use OneSMTP\Alerts\FailureAlertPayloadBuilder;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class FailureAlertPayloadBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_payload_excludes_raw_recipients_bodies_headers_payload_json_and_provider_config(): void
    {
        $GLOBALS['wpdb']->messageRowsById[44] = [
            'id' => 44,
            'message_uuid' => 'msg-44',
            'subject' => 'Reset password for CEO',
            'recipients_hash' => hash('sha256', 'recipient-list'),
            'body_hash' => hash('sha256', 'raw body'),
            'payload_json' => wp_json_encode([
                'to' => ['private@example.test', 'second@example.test'],
                'message' => 'Raw private message body token=abc123',
                'headers' => ['Authorization: Bearer secret-token'],
            ]),
            'status' => 'failed',
            'current_attempt' => 6,
            'max_attempts' => 6,
        ];
        $GLOBALS['wpdb']->providerRowsById[9] = [
            'id' => 9,
            'name' => 'Primary API Provider',
            'adapter_type' => 'sendgrid',
            'circuit_state' => 'open',
            'config_json' => wp_json_encode([
                'api_key' => 'SG.secret',
                'password' => 'provider-password',
            ]),
        ];

        $payload = (new FailureAlertPayloadBuilder())->build(
            ['attempt' => 6, 'reason' => 'missing_api_key', 'failure_category' => 'authentication'],
            44,
            9,
            123
        );
        $encoded = (string) wp_json_encode($payload);

        self::assertSame('terminal_failure', $payload['event']);
        self::assertSame(44, $payload['message']['id']);
        self::assertSame(hash('sha256', 'Reset password for CEO'), $payload['message']['subject_hash']);
        self::assertSame(hash('sha256', 'recipient-list'), $payload['message']['recipients_hash']);
        self::assertSame(hash('sha256', 'raw body'), $payload['message']['body_hash']);
        self::assertSame('sendgrid', $payload['provider']['adapter_type']);
        self::assertSame('authentication', $payload['failure']['category']);

        self::assertStringNotContainsString('private@example.test', $encoded);
        self::assertStringNotContainsString('second@example.test', $encoded);
        self::assertStringNotContainsString('Raw private message body', $encoded);
        self::assertStringNotContainsString('Authorization', $encoded);
        self::assertStringNotContainsString('secret-token', $encoded);
        self::assertStringNotContainsString('SG.secret', $encoded);
        self::assertStringNotContainsString('provider-password', $encoded);
        self::assertStringNotContainsString('Reset password for CEO', $encoded);
        self::assertArrayNotHasKey('payload_json', $payload['message']);
        self::assertArrayNotHasKey('config', $payload['provider']);
    }
}
