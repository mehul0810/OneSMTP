<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Repository;

use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\SecretVault;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class ProviderRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
    }

    public function test_save_encrypts_sensitive_config_and_find_decrypts_for_internal_use(): void
    {
        $repository = new ProviderRepository();
        $providerId = $repository->save(
            [
                'slug' => 'primary',
                'name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'is_active' => true,
                'config' => [
                    'host' => 'smtp.example.test',
                    'password' => 'plain-password',
                    'api_key' => 'plain-api-key',
                ],
            ]
        );

        self::assertSame(1, $providerId);
        self::assertNotEmpty($GLOBALS['wpdb']->inserts);

        $storedConfigJson = (string) ($GLOBALS['wpdb']->inserts[0]['data']['config_json'] ?? '');
        $storedConfig = json_decode($storedConfigJson, true);

        self::assertIsArray($storedConfig);
        self::assertSame('smtp.example.test', $storedConfig['host']);
        self::assertNotSame('plain-password', $storedConfig['password']);
        self::assertNotSame('plain-api-key', $storedConfig['api_key']);
        self::assertTrue((new SecretVault())->isEncrypted((string) $storedConfig['password']));
        self::assertTrue((new SecretVault())->isEncrypted((string) $storedConfig['api_key']));

        $GLOBALS['wpdb']->providerRowsById[$providerId] = [
            'id' => $providerId,
            'slug' => 'primary',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 100,
            'weight' => 1,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => $storedConfigJson,
        ];

        $provider = $repository->find($providerId);

        self::assertIsArray($provider);
        self::assertSame('plain-password', $provider['config']['password']);
        self::assertSame('plain-api-key', $provider['config']['api_key']);
    }

    public function test_malformed_encrypted_secret_is_unavailable_and_requires_recovery(): void
    {
        $repository = new ProviderRepository();
        $vault = new SecretVault();
        $malformedSecret = substr($vault->encrypt('placeholder-value'), 0, -8);

        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 100,
            'weight' => 1,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode(
                [
                    'host' => 'smtp.example.test',
                    'password' => $malformedSecret,
                ]
            ),
        ];

        $provider = $repository->find(7);

        self::assertIsArray($provider);
        self::assertSame('smtp.example.test', $provider['config']['host']);
        self::assertArrayNotHasKey('password', $provider['config']);
        self::assertTrue($repository->hasCredentialRecoveryRequired(7));
    }

    public function test_undecryptable_secret_is_unavailable_and_requires_recovery(): void
    {
        $repository = new ProviderRepository();

        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary SMTP',
            'adapter_type' => 'sendgrid',
            'priority' => 100,
            'weight' => 1,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode(
                [
                    'api_key' => $this->undecryptableSecretValue(),
                ]
            ),
        ];

        $provider = $repository->find(7);
        $safeProvider = $repository->findSafe(7);

        self::assertIsArray($provider);
        self::assertArrayNotHasKey('api_key', $provider['config']);
        self::assertIsArray($safeProvider);
        self::assertArrayNotHasKey('api_key', $safeProvider['config']);
        self::assertTrue($repository->hasCredentialRecoveryRequired(7));
    }

    public function test_provider_state_is_normalized_on_save_find_and_mark_state(): void
    {
        $repository = new ProviderRepository();

        $repository->save(
            [
                'slug' => 'primary',
                'name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'is_active' => true,
                'circuit_state' => '<script>alert(1)</script>',
                'circuit_until' => 'not-a-date',
                'config' => [],
            ]
        );

        self::assertSame('closed', $GLOBALS['wpdb']->inserts[0]['data']['circuit_state']);
        self::assertNull($GLOBALS['wpdb']->inserts[0]['data']['circuit_until']);

        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 100,
            'weight' => 1,
            'is_active' => 1,
            'circuit_state' => 'unexpected-state',
            'circuit_until' => '2026-07-01 12:30:00',
            'config_json' => wp_json_encode([]),
        ];

        $provider = $repository->find(7);

        self::assertIsArray($provider);
        self::assertSame('closed', $provider['circuit_state']);
        self::assertNull($provider['circuit_until']);

        $repository->markState(7, 'open', '2026-07-01 12:30:00');

        self::assertSame('open', $GLOBALS['wpdb']->updates[0]['data']['circuit_state']);
        self::assertSame('2026-07-01 12:30:00', $GLOBALS['wpdb']->updates[0]['data']['circuit_until']);
    }

    public function test_save_rejects_a_second_connection_for_the_same_provider_type(): void
    {
        $GLOBALS['wpdb']->activeProviders = [[
            'id' => 7,
            'slug' => 'emailit-primary',
            'name' => 'Emailit primary',
            'adapter_type' => 'emailit',
            'priority' => 100,
            'weight' => 1,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => '{}',
        ]];

        $providerId = (new ProviderRepository())->save([
            'name' => 'Emailit duplicate',
            'adapter_type' => 'emailit',
            'config' => ['api_key' => 'secret'],
        ]);

        self::assertSame(0, $providerId);
        self::assertSame([], $GLOBALS['wpdb']->inserts);
    }

    public function test_update_cannot_change_provider_to_an_already_configured_type(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            ['id' => 7, 'adapter_type' => 'emailit', 'priority' => 100, 'weight' => 1, 'is_active' => 1, 'circuit_state' => 'closed', 'config_json' => '{}'],
            ['id' => 8, 'adapter_type' => 'smtp', 'priority' => 100, 'weight' => 1, 'is_active' => 1, 'circuit_state' => 'closed', 'config_json' => '{}'],
        ];

        $providerId = (new ProviderRepository())->save([
            'id' => 8,
            'name' => 'Changed type',
            'adapter_type' => 'emailit',
            'config' => ['api_key' => 'secret'],
        ]);

        self::assertSame(0, $providerId);
        self::assertSame([], $GLOBALS['wpdb']->updates);
    }

    private function undecryptableSecretValue(): string
    {
        $parts = explode(':', (new SecretVault())->encrypt('placeholder-value'), 6);
        $parts[5][0] = $parts[5][0] === 'A' ? 'B' : 'A';

        return implode(':', $parts);
    }
}
