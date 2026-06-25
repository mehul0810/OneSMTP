<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Settings;

use InvalidArgumentException;
use OneSMTP\Security\SecretVault;
use OneSMTP\Settings\SettingsTransferService;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class SettingsTransferServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [
            'onesmtp_settings_import_nonce' => 'test-nonce',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_current_user_can'] = true;
        $GLOBALS['onesmtp_test_fired_actions'] = [];
        unset($GLOBALS['onesmtp_test_current_user_caps']);
    }

    public function test_export_excludes_provider_secrets_and_raw_recipient_destinations(): void
    {
        update_option('onesmtp_settings', [
            'sender_identity' => [
                'from_email' => 'sender@example.test',
                'from_name' => 'Sender',
                'reply_to' => ['reply@example.test'],
                'bcc' => ['audit@example.test'],
            ],
            'rate_limits' => [
                'per_minute' => 10,
                'per_hour' => 100,
                'per_day' => 1000,
            ],
            'failure_alerts' => [
                'email_enabled' => true,
                'email_recipients' => ['ops@example.test'],
                'webhook_enabled' => true,
                'webhook_url' => 'https://hooks.example.test/secret-path',
                'throttle_seconds' => 1800,
            ],
        ], false);

        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 7,
                'slug' => 'primary',
                'name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'priority' => 5,
                'weight' => 2,
                'is_active' => 1,
                'config_json' => wp_json_encode([
                    'host' => 'smtp.example.test',
                    'port' => '587',
                    'username' => 'smtp-user',
                    'password' => 'plain-password',
                    'api_key' => 'plain-api-key',
                    'client_secret' => 'plain-client-secret',
                    'refresh_token' => 'plain-refresh-token',
                    'from_email' => 'provider@example.test',
                ]),
            ],
        ];

        $payload = (new SettingsTransferService())->export();
        $json = wp_json_encode($payload);

        self::assertSame(1, $payload['schema_version']);
        self::assertSame('sender@example.test', $payload['settings']['sender_identity']['from_email']);
        self::assertSame(10, $payload['settings']['rate_limits']['per_minute']);
        self::assertSame(1800, $payload['settings']['failure_alerts']['throttle_seconds']);
        self::assertSame('smtp.example.test', $payload['providers'][0]['config']['host']);
        self::assertSame('587', $payload['providers'][0]['config']['port']);
        self::assertStringNotContainsString('reply@example.test', (string) $json);
        self::assertStringNotContainsString('audit@example.test', (string) $json);
        self::assertStringNotContainsString('ops@example.test', (string) $json);
        self::assertStringNotContainsString('hooks.example.test', (string) $json);
        self::assertStringNotContainsString('smtp-user', (string) $json);
        self::assertStringNotContainsString('plain-password', (string) $json);
        self::assertStringNotContainsString('plain-api-key', (string) $json);
        self::assertStringNotContainsString('plain-client-secret', (string) $json);
        self::assertStringNotContainsString('plain-refresh-token', (string) $json);
        self::assertStringNotContainsString('provider@example.test', (string) $json);
        self::assertStringNotContainsString('payload_json', (string) $json);
    }

    public function test_export_handles_empty_default_state(): void
    {
        $payload = (new SettingsTransferService())->export();

        self::assertSame('', $payload['settings']['sender_identity']['from_email']);
        self::assertSame(0, $payload['settings']['rate_limits']['per_minute']);
        self::assertSame(900, $payload['settings']['failure_alerts']['throttle_seconds']);
        self::assertSame([], $payload['providers']);
    }

    public function test_import_sanitizes_settings_and_ignores_unsafe_fields(): void
    {
        $json = wp_json_encode([
            'settings' => [
                'sender_identity' => [
                    'from_email' => ' sender@example.test ',
                    'from_name' => '<b>Sender</b>',
                    'reply_to' => ['reply@example.test'],
                    'bcc' => ['audit@example.test'],
                    'force_from_email' => true,
                    'api_key' => 'settings-secret',
                ],
                'rate_limits' => [
                    'per_minute' => '-1',
                    'per_hour' => '120',
                    'per_day' => '2000000',
                ],
                'failure_alerts' => [
                    'email_enabled' => true,
                    'email_recipients' => ['ops@example.test'],
                    'webhook_enabled' => true,
                    'webhook_url' => 'https://hooks.example.test/secret-path',
                    'throttle_seconds' => '999999',
                ],
            ],
            'providers' => [
                [
                    'slug' => 'primary',
                    'name' => '<b>Primary SMTP</b>',
                    'adapter_type' => 'smtp',
                    'priority' => '4',
                    'weight' => '2',
                    'is_active' => true,
                    'config' => [
                        'host' => '<b>smtp.example.test</b>',
                        'port' => '587',
                        'username' => 'smtp-user',
                        'password' => 'plain-password',
                        'api_key' => 'plain-api-key',
                        'from_email' => 'provider@example.test',
                    ],
                ],
            ],
        ]);

        $summary = (new SettingsTransferService())->importJson((string) $json);
        $settings = get_option('onesmtp_settings', []);
        $storedProviderConfig = json_decode((string) $GLOBALS['wpdb']->inserts[0]['data']['config_json'], true);

        self::assertSame(['sender_identity', 'rate_limits', 'failure_alerts'], $summary['settings_groups']);
        self::assertSame(1, $summary['providers_imported']);
        self::assertGreaterThanOrEqual(6, $summary['excluded_fields']);
        self::assertSame('sender@example.test', $settings['sender_identity']['from_email']);
        self::assertSame('Sender', $settings['sender_identity']['from_name']);
        self::assertArrayNotHasKey('reply_to', array_filter($settings['sender_identity']));
        self::assertArrayNotHasKey('bcc', array_filter($settings['sender_identity']));
        self::assertSame(0, $settings['rate_limits']['per_minute']);
        self::assertSame(120, $settings['rate_limits']['per_hour']);
        self::assertSame(1000000, $settings['rate_limits']['per_day']);
        self::assertFalse($settings['failure_alerts']['email_enabled']);
        self::assertSame([], $settings['failure_alerts']['email_recipients']);
        self::assertFalse($settings['failure_alerts']['webhook_enabled']);
        self::assertSame('', $settings['failure_alerts']['webhook_url']);
        self::assertSame(86400, $settings['failure_alerts']['throttle_seconds']);
        self::assertSame('smtp.example.test', $storedProviderConfig['host']);
        self::assertSame('587', $storedProviderConfig['port']);
        self::assertArrayNotHasKey('username', $storedProviderConfig);
        self::assertArrayNotHasKey('password', $storedProviderConfig);
        self::assertArrayNotHasKey('api_key', $storedProviderConfig);
        self::assertArrayNotHasKey('from_email', $storedProviderConfig);
    }

    public function test_import_updates_existing_provider_by_slug_without_overwriting_stored_secret(): void
    {
        $vault = new SecretVault();
        $storedPassword = $vault->encrypt('existing-password');
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 7,
                'slug' => 'primary',
                'name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'priority' => 10,
                'weight' => 1,
                'is_active' => 1,
                'config_json' => wp_json_encode([
                    'host' => 'old.example.test',
                    'password' => $storedPassword,
                ]),
            ],
        ];
        $GLOBALS['wpdb']->providerRowsById[7] = $GLOBALS['wpdb']->activeProviders[0];

        $json = wp_json_encode([
            'providers' => [
                [
                    'slug' => 'primary',
                    'name' => 'Primary SMTP',
                    'adapter_type' => 'smtp',
                    'config' => [
                        'host' => 'new.example.test',
                        'password' => 'imported-password',
                    ],
                ],
            ],
        ]);

        $summary = (new SettingsTransferService())->importJson((string) $json);
        $updatedConfig = json_decode((string) $GLOBALS['wpdb']->updates[0]['data']['config_json'], true);

        self::assertSame(1, $summary['providers_imported']);
        self::assertSame(['id' => 7], $GLOBALS['wpdb']->updates[0]['where']);
        self::assertSame('new.example.test', $updatedConfig['host']);
        self::assertSame('existing-password', $vault->decrypt((string) $updatedConfig['password']));
    }

    public function test_import_rejects_malformed_and_empty_payloads(): void
    {
        $service = new SettingsTransferService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Import JSON must be a valid JSON object.');
        $service->importJson('{not-json');
    }

    public function test_import_rejects_payload_without_supported_settings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Import JSON does not contain supported OneSMTP settings.');

        (new SettingsTransferService())->importJson((string) wp_json_encode(['settings' => ['unknown' => true]]));
    }

    public function test_import_fires_audit_safe_summary_action(): void
    {
        $summary = (new SettingsTransferService())->importJson((string) wp_json_encode([
            'settings' => [
                'rate_limits' => [
                    'per_hour' => 60,
                ],
            ],
        ]));

        self::assertSame(['rate_limits'], $summary['settings_groups']);
        self::assertNotEmpty($GLOBALS['onesmtp_test_fired_actions']);
        $action = end($GLOBALS['onesmtp_test_fired_actions']);
        self::assertSame('onesmtp_settings_imported', $action['hook']);
        self::assertSame($summary, $action['args'][0]);
        self::assertStringNotContainsString('example.test', (string) wp_json_encode($action));
        self::assertStringNotContainsString('secret', strtolower((string) wp_json_encode($action)));
    }
}
