<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\ProviderAdmin;
use OneSMTP\Dns\DnsResolverInterface;
use OneSMTP\Dns\DomainAuthenticationChecker;
use OneSMTP\Product\FeatureGate;
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
        self::assertStringContainsString('onesmtp-provider-connection-summary', $output);
        self::assertStringContainsString('smtp.example.test', $output);
        self::assertStringContainsString('Circuit open', $output);
        self::assertStringContainsString('Open until 2026-07-01 12:30:00 GMT.', $output);
        self::assertStringContainsString('&quot;connections&quot;:[{&quot;id&quot;:7', $output);
        self::assertStringContainsString('[REDACTED]', $output);
        self::assertStringNotContainsString('plain-password', $output);
        self::assertStringNotContainsString('plain-api-key', $output);
        self::assertStringNotContainsString('onesmtp-provider-connections', $output);
        self::assertStringNotContainsString('Provider capability matrix', $output);
        self::assertStringNotContainsString('DNS authentication readiness', $output);
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

    public function test_render_exposes_accessible_quota_controls_only_when_pro_is_enabled(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 9,
                'name' => 'Budgeted SMTP',
                'adapter_type' => 'smtp',
                'priority' => 1,
                'weight' => 1,
                'is_active' => 1,
                'circuit_state' => 'closed',
                'config_json' => wp_json_encode([
                    'host' => 'smtp.example.test',
                    'quota_per_minute' => 7,
                    'quota_per_hour' => 20,
                    'quota_per_day' => 100,
                ]),
            ],
        ];

        ob_start();
        (new ProviderAdmin(new ProviderRepository(), featureGate: new FeatureGate([
            FeatureGate::PROVIDER_QUOTA_BUDGETS => true,
        ], true)))->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Provider sending budget', $output);
        self::assertStringContainsString('Per-minute attempts', $output);
        self::assertStringContainsString('max="1000000"', $output);
        self::assertStringContainsString('aria-describedby="onesmtp-provider-quota-help"', $output);
        self::assertStringNotContainsString('quota[per_minute]', $output);
    }

    public function test_render_hides_mailgun_webhook_controls_when_provider_events_are_disabled(): void
    {
        ob_start();
        (new ProviderAdmin(new ProviderRepository()))->render();
        $output = (string) ob_get_clean();

        self::assertStringNotContainsString('Mailgun webhook signing key', $output);
        self::assertStringNotContainsString('onesmtp-mailgun-webhook-guidance', $output);
        self::assertStringNotContainsString('providerEventsEnabled&quot;:true', $output);
    }

    public function test_render_keeps_generic_form_mailgun_controls_provider_specific_even_when_enabled(): void
    {
        ob_start();
        (new ProviderAdmin(
            new ProviderRepository(),
            featureGate: new FeatureGate([FeatureGate::PROVIDER_EVENTS => true], true)
        ))->render();
        $output = (string) ob_get_clean();

        self::assertStringNotContainsString('Mailgun webhook signing key', $output);
        self::assertStringNotContainsString('onesmtp-mailgun-webhook-guidance', $output);
        self::assertStringContainsString('providerEventsEnabled&quot;:true', $output);
        self::assertStringContainsString('webhookEndpoint&quot;:&quot;https:\\/\\/example.org', $output);
    }

    public function test_render_exposes_oauth_lifecycle_only_when_the_pro_gate_is_enabled(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 8,
                'name' => 'Fixture Gmail',
                'adapter_type' => 'gmail',
                'priority' => 1,
                'weight' => 1,
                'is_active' => 0,
                'circuit_state' => 'closed',
                'config_json' => wp_json_encode([
                    'client_id' => 'fixture-client',
                    'client_secret' => 'fixture-secret',
                ]),
            ],
        ];

        ob_start();
        (new ProviderAdmin(new ProviderRepository()))->render();
        $freeOutput = (string) ob_get_clean();

        ob_start();
        (new ProviderAdmin(
            new ProviderRepository(),
            featureGate: new FeatureGate([ FeatureGate::PROVIDER_AUTH_LIFECYCLE => true ], true)
        ))->render();
        $proOutput = (string) ob_get_clean();

        self::assertStringContainsString('oauthEnabled&quot;:false', $freeOutput);
        self::assertStringNotContainsString('https:\\/\\/example.org\\/wp-json\\/onesmtp\\/v1\\/providers\\/8\\/oauth\\/callback', $freeOutput);
        self::assertStringContainsString('oauthEnabled&quot;:true', $proOutput);
        self::assertStringContainsString('oauthCallbackBase&quot;:&quot;https:\\/\\/example.org', $proOutput);
        self::assertStringNotContainsString('fixture-secret', $freeOutput . $proOutput);
    }

    public function test_render_shows_quiet_suremail_analysis_card_without_global_notice_actions(): void
    {
        update_option('suremails_connections', [
            'connections' => [
                'default-id' => [
                    'id' => 'default-id',
                    'type' => 'EMAILIT',
                    'connection_title' => 'Imported Emailit',
                    'api_key' => rtrim(base64_encode('secret'), '='),
                ],
            ],
            'default_connection' => ['id' => 'default-id', 'type' => 'EMAILIT'],
        ], false);

        ob_start();
        (new ProviderAdmin(new ProviderRepository()))->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('SureMail compatibility', $output);
        self::assertStringContainsString('Only one mail plugin should own live delivery at a time.', $output);
        self::assertStringContainsString('Analyze SureMail setup', $output);
        self::assertStringNotContainsString('deactivate', strtolower($output));
        self::assertStringNotContainsString(rtrim(base64_encode('secret'), '='), $output);
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
        $admin->renderAdvancedTools();
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

        self::assertStringContainsString('Credential update needed. Re-enter the affected credentials to restore delivery.', $output);
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
        $this->expectExceptionMessage('You do not have permission to manage Aculect Mail providers.');

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
            self::assertSame('Aculect Mail provider admin redirected.', $e->getMessage());
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
            self::assertSame('Aculect Mail provider admin redirected.', $e->getMessage());
        }

        self::assertSame(1, $GLOBALS['wpdb']->updates[0]['data']['priority']);
        self::assertSame(1, $GLOBALS['wpdb']->updates[0]['data']['weight']);
    }

    public function test_save_writes_audit_event_without_raw_secret_values(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_provider_action' => 'save',
            'onesmtp_provider_nonce' => 'test-nonce',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => '10',
            'weight' => '1',
            'is_active' => '1',
            'config' => [
                'host' => 'smtp.example.test',
                'password' => 'secret-password',
                'api_key' => 'secret-api-key',
            ],
        ];

        try {
            (new ProviderAdmin(new ProviderRepository()))->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('Aculect Mail provider admin redirected.', $e->getMessage());
        }

        $audit = end($GLOBALS['wpdb']->inserts);
        self::assertSame('audit_provider_changed', $audit['data']['event_type']);
        $json = (string) $audit['data']['context_json'];
        self::assertStringContainsString('"safe_config_fields":["host"]', $json);
        self::assertStringContainsString('"credential_fields_updated":["api_key","password"]', $json);
        self::assertStringNotContainsString('secret-password', $json);
        self::assertStringNotContainsString('secret-api-key', $json);
    }

    public function test_pro_quota_post_is_clamped_and_audit_contains_only_safe_limits(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_provider_action' => 'save',
            'onesmtp_provider_nonce' => 'test-nonce',
            'name' => 'Budgeted SMTP',
            'adapter_type' => 'smtp',
            'priority' => '10',
            'weight' => '1',
            'is_active' => '1',
            'config' => [
                'host' => 'smtp.example.test',
                'quota_per_minute' => '999999999999999999999999',
                'quota_per_hour' => '-3',
                'quota_per_day' => '',
                'password' => 'secret-value',
            ],
        ];

        $gate = new FeatureGate([FeatureGate::PROVIDER_QUOTA_BUDGETS => true], true);
        try {
            (new ProviderAdmin(new ProviderRepository(), featureGate: $gate))->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('Aculect Mail provider admin redirected.', $e->getMessage());
        }

        $config = json_decode((string) $GLOBALS['wpdb']->inserts[0]['data']['config_json'], true);
        self::assertSame(1000000, $config['quota_per_minute']);
        self::assertSame(0, $config['quota_per_hour']);
        self::assertSame(0, $config['quota_per_day']);
        $audit = end($GLOBALS['wpdb']->inserts);
        $json = (string) $audit['data']['context_json'];
        self::assertStringContainsString('"quota_enabled":true', $json);
        self::assertStringContainsString('"per_minute":1000000', $json);
        self::assertStringNotContainsString('secret-value', $json);
        self::assertStringNotContainsString('qa@example.com', $json);
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
            self::assertSame('Aculect Mail provider admin redirected.', $e->getMessage());
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
            self::assertSame('Aculect Mail provider admin redirected.', $e->getMessage());
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
