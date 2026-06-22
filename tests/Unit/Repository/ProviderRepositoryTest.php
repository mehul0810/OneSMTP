<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Repository;

use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\SecretVault;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class ProviderRepositoryTest extends TestCase
{
    public function test_save_encrypts_sensitive_config_and_find_decrypts_for_internal_use(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();

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
}
