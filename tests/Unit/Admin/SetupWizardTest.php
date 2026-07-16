<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\SetupWizard;
use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Providers\SendResult;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\SecretVault;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SetupWizardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_current_user_can'] = true;
        $GLOBALS['onesmtp_test_options'] = [
            'admin_email' => [
                'value' => 'admin@example.test',
                'autoload' => true,
            ],
        ];

        unset($GLOBALS['onesmtp_test_redirect'], $GLOBALS['onesmtp_test_wp_die']);
    }

    public function test_render_empty_state_guides_admin_to_first_provider_without_secret_values(): void
    {
        $wizard = new SetupWizard(new ProviderRepository());

        ob_start();
        $wizard->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('onesmtp-setup-shell', $output);
        self::assertStringContainsString('onesmtp-setup-rail', $output);
        self::assertStringContainsString('Current state', $output);
        self::assertStringContainsString('Needs setup', $output);
        self::assertStringContainsString('Save first provider', $output);
        self::assertStringContainsString('Add and activate a provider before sending a setup test email.', $output);
        self::assertStringNotContainsString('plain-password', $output);
    }

    public function test_render_includes_provider_capability_matrix_from_metadata(): void
    {
        $wizard = new SetupWizard(new ProviderRepository());

        ob_start();
        $wizard->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('onesmtp-setup-panel postbox', $output);
        self::assertStringContainsString('Setup guidance', $output);
        self::assertStringContainsString('Provider capability matrix', $output);
        self::assertStringContainsString('API delivery', $output);
        self::assertStringContainsString('Provider message ID', $output);

        foreach (ProviderTypes::metadata() as $type => $provider) {
            self::assertStringContainsString('<code>' . $type . '</code>', $output);
            self::assertStringContainsString($provider['label'], $output);
        }
    }

    public function test_render_marks_unavailable_capabilities_without_blocking_setup(): void
    {
        $wizard = new SetupWizard(new ProviderRepository());

        ob_start();
        $wizard->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Unavailable', $output);
        self::assertStringContainsString('Unavailable capabilities do not block setup.', $output);
        self::assertStringContainsString('Save first provider', $output);
        self::assertStringNotContainsString('secret-api-key', $output);
        self::assertStringNotContainsString('secret-password', $output);
    }

    public function test_non_manager_cannot_mutate_setup_state(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_setup_action' => 'save_provider',
            'onesmtp_setup_nonce' => 'test-nonce',
        ];
        $GLOBALS['onesmtp_test_current_user_can'] = false;

        $wizard = new SetupWizard(new ProviderRepository());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to run the OneSMTP setup wizard.');

        $wizard->handleRequest();
    }

    public function test_save_provider_sanitizes_payload_encrypts_secret_and_logs_setup_event(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_setup_action' => 'save_provider',
            'onesmtp_setup_nonce' => 'test-nonce',
            'from_email' => ' admin@example.test ',
            'from_name' => ' <b>Admin Sender</b> ',
            'name' => ' <strong>Primary API</strong> ',
            'adapter_type' => 'sendgrid',
            'api_key' => 'secret-api-key',
            'host' => '<script>smtp.example.test</script>',
            'password' => 'secret-password',
        ];

        $wizard = new SetupWizard(new ProviderRepository(), null, new EventRepository());

        try {
            $wizard->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP setup wizard redirected.', $e->getMessage());
        }

        self::assertCount(3, $GLOBALS['wpdb']->inserts);

        $providerInsert = $GLOBALS['wpdb']->inserts[0];
        $eventInsert = $GLOBALS['wpdb']->inserts[1];
        $auditInsert = $GLOBALS['wpdb']->inserts[2];
        $config = json_decode((string) $providerInsert['data']['config_json'], true);
        $vault = new SecretVault();

        self::assertSame('Primary API', $providerInsert['data']['name']);
        self::assertSame('sendgrid', $providerInsert['data']['adapter_type']);
        self::assertSame(1, $providerInsert['data']['is_active']);
        self::assertSame('admin@example.test', $config['from_email']);
        self::assertSame('Admin Sender', $config['from_name']);
        self::assertSame('smtp.example.test', $config['host']);
        self::assertSame('secret-api-key', $vault->decrypt((string) $config['api_key']));
        self::assertSame('secret-password', $vault->decrypt((string) $config['password']));
        self::assertSame('setup_provider_saved', $eventInsert['data']['event_type']);
        self::assertSame('audit_provider_changed', $auditInsert['data']['event_type']);
        self::assertStringNotContainsString('secret-api-key', (string) $auditInsert['data']['context_json']);
        self::assertStringNotContainsString('secret-password', (string) $auditInsert['data']['context_json']);
        self::assertStringContainsString('onesmtp_setup_status=provider_saved', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_send_test_email_uses_existing_provider_without_rendering_secret_and_logs_safe_result(): void
    {
        $vault = new SecretVault();
        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary API',
            'adapter_type' => 'sendgrid',
            'priority' => 1,
            'weight' => 1,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode(
                [
                    'api_key' => $vault->encrypt('secret-api-key'),
                ]
            ),
        ];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_setup_action' => 'send_test',
            'onesmtp_setup_nonce' => 'test-nonce',
            'provider_id' => '7',
            'test_to' => 'recipient@example.test',
        ];

        $adapter = new SetupTestAdapter(new SendResult(true, 'accepted', 'Accepted by provider.'));
        $wizard = new SetupWizard(
            new ProviderRepository(),
            new ProviderDeliveryManager(new ProviderAdapterRegistry(['sendgrid' => $adapter])),
            new EventRepository()
        );

        try {
            $wizard->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP setup wizard redirected.', $e->getMessage());
        }

        self::assertSame(['recipient@example.test'], $adapter->lastMessage['to'] ?? []);
        self::assertSame('secret-api-key', $adapter->lastConfig['api_key'] ?? null);
        self::assertCount(1, $GLOBALS['wpdb']->inserts);
        self::assertSame('setup_test_email', $GLOBALS['wpdb']->inserts[0]['data']['event_type']);

        $context = json_decode((string) $GLOBALS['wpdb']->inserts[0]['data']['context_json'], true);

        self::assertSame(['ok' => true, 'code' => 'accepted'], $context);
        self::assertStringNotContainsString('secret-api-key', (string) $GLOBALS['wpdb']->inserts[0]['data']['context_json']);
        self::assertStringContainsString('onesmtp_setup_status=test_sent', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    public function test_send_test_email_treats_unavailable_secret_as_missing(): void
    {
        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'slug' => 'primary',
            'name' => 'Primary API',
            'adapter_type' => 'sendgrid',
            'priority' => 1,
            'weight' => 1,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode(
                [
                    'api_key' => $this->undecryptableSecretValue(),
                ]
            ),
        ];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_setup_action' => 'send_test',
            'onesmtp_setup_nonce' => 'test-nonce',
            'provider_id' => '7',
            'test_to' => 'recipient@example.test',
        ];

        $adapter = new SetupTestAdapter(new SendResult(true, 'accepted', 'Accepted by provider.'));
        $wizard = new SetupWizard(
            new ProviderRepository(),
            new ProviderDeliveryManager(new ProviderAdapterRegistry(['sendgrid' => $adapter])),
            new EventRepository()
        );

        try {
            $wizard->handleRequest();
        } catch (RuntimeException $e) {
            self::assertSame('OneSMTP setup wizard redirected.', $e->getMessage());
        }

        self::assertArrayNotHasKey('api_key', $adapter->lastConfig);
        self::assertStringContainsString('onesmtp_setup_status=test_sent', (string) $GLOBALS['onesmtp_test_redirect']['location']);
    }

    private function undecryptableSecretValue(): string
    {
        $parts = explode(':', (new SecretVault())->encrypt('placeholder-value'), 6);
        $parts[5][0] = $parts[5][0] === 'A' ? 'B' : 'A';

        return implode(':', $parts);
    }
}

final class SetupTestAdapter implements ProviderAdapterInterface
{
    /** @var array<string,mixed> */
    public array $lastMessage = [];

    /** @var array<string,mixed> */
    public array $lastConfig = [];

    public function __construct(private SendResult $result)
    {
    }

    public function getSlug(): string
    {
        return 'sendgrid';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $this->lastMessage = $message;
        $this->lastConfig = $config->all();

        return $this->result;
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        $this->lastConfig = $config->all();

        return $this->result;
    }
}
