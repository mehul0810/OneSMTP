<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\ProviderAdmin;
use OneSMTP\Dns\DnsResolverInterface;
use OneSMTP\Dns\DomainAuthenticationChecker;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\SecretVault;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProviderAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_current_user_can'] = true;
        unset($GLOBALS['onesmtp_test_redirect'], $GLOBALS['onesmtp_test_wp_die']);
    }

    public function test_render_lists_safe_provider_data_without_raw_secret_values(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 7,
                'slug' => 'primary',
                'name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'priority' => 10,
                'weight' => 2,
                'is_active' => 1,
                'circuit_state' => 'open',
                'circuit_until' => '2026-07-01 12:30:00',
                'config_json' => wp_json_encode(
                    [
                        'host' => 'smtp.example.test',
                        'password' => 'plain-password',
                        'api_key' => 'plain-api-key',
                        'from_email' => 'sender@example.test',
                        'dkim_selector' => 'mail',
                    ]
                ),
            ],
        ];

        $admin = new ProviderAdmin(
            new ProviderRepository(),
            new DomainAuthenticationChecker(
                new ProviderAdminDnsResolver(
                    true,
                    [
                        'example.test' => ['v=spf1 include:_spf.example.test -all'],
                        'mail._domainkey.example.test' => ['v=DKIM1; k=rsa; p=synthetic'],
                        '_dmarc.example.test' => ['v=DMARC1; p=none'],
                    ]
                )
            )
        );

        ob_start();
        $admin->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Primary SMTP', $output);
        self::assertStringContainsString('Provider capability matrix', $output);
        self::assertStringContainsString('API delivery', $output);
        self::assertStringContainsString('Unavailable', $output);
        self::assertStringContainsString('smtp.example.test', $output);
        self::assertStringContainsString('Circuit open', $output);
        self::assertStringContainsString('Open until 2026-07-01 12:30:00 GMT.', $output);
        self::assertStringContainsString('DNS authentication readiness', $output);
        self::assertStringContainsString('example.test', $output);
        self::assertStringContainsString('TXT evidence found', $output);
        self::assertStringContainsString('[REDACTED]', $output);
        self::assertStringNotContainsString('plain-password', $output);
        self::assertStringNotContainsString('plain-api-key', $output);
        self::assertStringContainsString('php_mail', $output);
        self::assertStringContainsString('sendgrid', $output);
        self::assertStringContainsString('postmark', $output);
        self::assertStringContainsString('brevo', $output);
        self::assertStringContainsString('smtp', $output);
    }

    public function test_render_normalizes_invalid_provider_health_state(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 7,
                'slug' => 'primary',
                'name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'priority' => 10,
                'weight' => 2,
                'is_active' => 1,
                'circuit_state' => '<script>alert(1)</script>',
                'circuit_until' => 'not-a-date',
                'config_json' => wp_json_encode([]),
            ],
        ];

        $admin = new ProviderAdmin(new ProviderRepository());

        ob_start();
        $admin->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Circuit closed', $output);
        self::assertStringNotContainsString('<script>alert(1)</script>', $output);
        self::assertStringNotContainsString('not-a-date', $output);
    }

    public function test_render_dns_authentication_handles_missing_lookup_without_claiming_validity(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 8,
                'slug' => 'fallback',
                'name' => 'Fallback SMTP',
                'adapter_type' => 'smtp',
                'priority' => 20,
                'weight' => 1,
                'is_active' => 1,
                'config_json' => wp_json_encode(
                    [
                        'from_email' => 'sender@example.test',
                    ]
                ),
            ],
        ];

        $admin = new ProviderAdmin(
            new ProviderRepository(),
            new DomainAuthenticationChecker(new ProviderAdminDnsResolver(false, []))
        );

        ob_start();
        $admin->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('DNS TXT lookup is not available in this PHP environment.', $output);
        self::assertStringContainsString('Inconclusive', $output);
        self::assertStringContainsString('Add a DKIM selector to enable selector-specific checks.', $output);
        self::assertStringNotContainsString('TXT evidence found', $output);
    }

    public function test_render_shows_generic_recovery_required_message_without_secret_details(): void
    {
        $providerRow = [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 10,
            'weight' => 2,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode(
                [
                    'host' => 'smtp.example.test',
                    'password' => $this->undecryptableSecretValue(),
                ]
            ),
        ];

        $GLOBALS['wpdb']->activeProviders = [$providerRow];
        $GLOBALS['wpdb']->providerRowsById[7] = $providerRow;

        $admin = new ProviderAdmin(new ProviderRepository());

        ob_start();
        $admin->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Credential recovery required. Re-enter affected credentials to restore delivery.', $output);
        self::assertStringNotContainsString('placeholder-value', $output);
    }

    public function test_non_manager_cannot_mutate_provider_state(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_provider_action' => 'delete',
            'provider_id' => '7',
            'onesmtp_provider_nonce' => 'test-nonce',
        ];
        $GLOBALS['onesmtp_test_current_user_can'] = false;

        $admin = new ProviderAdmin(new ProviderRepository());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to manage OneSMTP providers.');

        $admin->handleRequest();
    }

    public function test_update_preserves_existing_secret_when_secret_field_is_blank(): void
    {
        $vault = new SecretVault();
        $storedPassword = $vault->encrypt('existing-password');

        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 10,
            'weight' => 2,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode(
                [
                    'host' => 'old.example.test',
                    'password' => $storedPassword,
                ]
            ),
        ];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_provider_action' => 'save',
            'onesmtp_provider_nonce' => 'test-nonce',
            'provider_id' => '7',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => '5',
            'weight' => '3',
            'is_active' => '1',
            'config' => [
                'host' => 'new.example.test',
                'password' => '',
            ],
        ];

        $admin = new ProviderAdmin(new ProviderRepository());

        try {
            $admin->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP provider admin redirected.', $e->getMessage());
        }

        self::assertNotEmpty($GLOBALS['wpdb']->updates);

        $updatedConfig = json_decode((string) $GLOBALS['wpdb']->updates[0]['data']['config_json'], true);

        self::assertIsArray($updatedConfig);
        self::assertSame('new.example.test', $updatedConfig['host']);
        self::assertSame('existing-password', $vault->decrypt((string) $updatedConfig['password']));
        self::assertSame(5, $GLOBALS['wpdb']->updates[0]['data']['priority']);
        self::assertSame(3, $GLOBALS['wpdb']->updates[0]['data']['weight']);
        self::assertSame(1, $GLOBALS['wpdb']->updates[0]['data']['is_active']);
        self::assertStringContainsString('onesmtp_provider_status=saved', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_update_normalizes_invalid_priority_and_weight_to_safe_minimums(): void
    {
        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 10,
            'weight' => 2,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode([]),
        ];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_provider_action' => 'save',
            'onesmtp_provider_nonce' => 'test-nonce',
            'provider_id' => '7',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => '-10',
            'weight' => '0',
            'is_active' => '1',
        ];

        $admin = new ProviderAdmin(new ProviderRepository());

        try {
            $admin->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP provider admin redirected.', $e->getMessage());
        }

        self::assertSame(1, $GLOBALS['wpdb']->updates[0]['data']['priority']);
        self::assertSame(1, $GLOBALS['wpdb']->updates[0]['data']['weight']);
    }

    public function test_update_preserves_unavailable_secret_when_secret_field_is_blank(): void
    {
        $storedPassword = $this->undecryptableSecretValue();

        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 10,
            'weight' => 2,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode(
                [
                    'host' => 'old.example.test',
                    'password' => $storedPassword,
                ]
            ),
        ];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_provider_action' => 'save',
            'onesmtp_provider_nonce' => 'test-nonce',
            'provider_id' => '7',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => '5',
            'weight' => '3',
            'is_active' => '1',
            'config' => [
                'host' => 'new.example.test',
                'password' => '',
            ],
        ];

        $repository = new ProviderRepository();
        $admin = new ProviderAdmin($repository);

        try {
            $admin->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP provider admin redirected.', $e->getMessage());
        }

        $updatedConfig = json_decode((string) $GLOBALS['wpdb']->updates[0]['data']['config_json'], true);

        self::assertIsArray($updatedConfig);
        self::assertSame('new.example.test', $updatedConfig['host']);
        self::assertArrayHasKey('password', $updatedConfig);
        self::assertTrue((new SecretVault())->isEncrypted((string) $updatedConfig['password']));

        $GLOBALS['wpdb']->providerRowsById[7]['config_json'] = (string) $GLOBALS['wpdb']->updates[0]['data']['config_json'];

        self::assertTrue($repository->hasCredentialRecoveryRequired(7));
        self::assertStringContainsString('onesmtp_provider_status=saved', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_update_reentered_secret_recovers_unavailable_secret(): void
    {
        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 10,
            'weight' => 2,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode(
                [
                    'host' => 'old.example.test',
                    'password' => $this->undecryptableSecretValue(),
                ]
            ),
        ];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_provider_action' => 'save',
            'onesmtp_provider_nonce' => 'test-nonce',
            'provider_id' => '7',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => '5',
            'weight' => '3',
            'is_active' => '1',
            'config' => [
                'host' => 'new.example.test',
                'password' => 'replacement-value',
            ],
        ];

        $repository = new ProviderRepository();
        $admin = new ProviderAdmin($repository);

        try {
            $admin->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP provider admin redirected.', $e->getMessage());
        }

        $updatedConfig = json_decode((string) $GLOBALS['wpdb']->updates[0]['data']['config_json'], true);
        $vault = new SecretVault();

        self::assertIsArray($updatedConfig);
        self::assertSame('new.example.test', $updatedConfig['host']);
        self::assertSame('replacement-value', $vault->decrypt((string) $updatedConfig['password']));

        $GLOBALS['wpdb']->providerRowsById[7]['config_json'] = (string) $GLOBALS['wpdb']->updates[0]['data']['config_json'];

        self::assertFalse($repository->hasCredentialRecoveryRequired(7));
        self::assertStringContainsString('onesmtp_provider_status=saved', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    private function undecryptableSecretValue(): string
    {
        $parts = explode(':', (new SecretVault())->encrypt('placeholder-value'), 6);
        $parts[5][0] = $parts[5][0] === 'A' ? 'B' : 'A';

        return implode(':', $parts);
    }
}

final class ProviderAdminDnsResolver implements DnsResolverInterface
{
    /**
     * @param array<string,array<int,string>> $records
     */
    public function __construct(private bool $available, private array $records)
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function txt(string $domain): array
    {
        return $this->records[$domain] ?? [];
    }
}
