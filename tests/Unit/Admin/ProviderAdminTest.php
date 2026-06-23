<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\ProviderAdmin;
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
                'config_json' => wp_json_encode(
                    [
                        'host' => 'smtp.example.test',
                        'password' => 'plain-password',
                        'api_key' => 'plain-api-key',
                    ]
                ),
            ],
        ];

        $admin = new ProviderAdmin(new ProviderRepository());

        ob_start();
        $admin->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Primary SMTP', $output);
        self::assertStringContainsString('Provider capability matrix', $output);
        self::assertStringContainsString('API delivery', $output);
        self::assertStringContainsString('Unavailable', $output);
        self::assertStringContainsString('smtp.example.test', $output);
        self::assertStringContainsString('[REDACTED]', $output);
        self::assertStringNotContainsString('plain-password', $output);
        self::assertStringNotContainsString('plain-api-key', $output);
        self::assertStringContainsString('php_mail', $output);
        self::assertStringContainsString('sendgrid', $output);
        self::assertStringContainsString('postmark', $output);
        self::assertStringContainsString('brevo', $output);
        self::assertStringContainsString('smtp', $output);
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
}
