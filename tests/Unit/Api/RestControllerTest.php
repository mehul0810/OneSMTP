<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Api;

use OneSMTP\Api\RestController;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class RestControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_rest_routes'] = [];
    }

    public function test_register_routes_adds_validation_arguments(): void
    {
        $controller = $this->controllerWithoutConstructor();

        $controller->registerRoutes();

        $routes = $GLOBALS['onesmtp_test_rest_routes'];
        self::assertCount(6, $routes);

        $providerWriteRoute = $routes[0]['args'][1];
        self::assertArrayHasKey('args', $providerWriteRoute);
        self::assertArrayHasKey('adapter_type', $providerWriteRoute['args']);
        self::assertSame(['smtp', 'php_mail', 'gmail', 'sendgrid', 'postmark', 'brevo'], $providerWriteRoute['args']['adapter_type']['enum']);

        $messagesRoute = $routes[3]['args'][0];
        self::assertArrayHasKey('limit', $messagesRoute['args']);
        self::assertSame(200, $messagesRoute['args']['limit']['maximum']);

        $resendRoute = $routes[5]['args'][0];
        self::assertArrayHasKey('id', $resendRoute['args']);
        self::assertArrayHasKey('provider_id', $resendRoute['args']);
    }

    public function test_id_and_limit_validators_reject_invalid_values(): void
    {
        self::assertTrue(RestController::validatePositiveId(1));
        self::assertTrue(RestController::validatePositiveId('20'));
        self::assertFalse(RestController::validatePositiveId(0));
        self::assertFalse(RestController::validatePositiveId('abc'));

        self::assertTrue(RestController::validateOptionalPositiveId(null));
        self::assertTrue(RestController::validateOptionalPositiveId(''));
        self::assertFalse(RestController::validateOptionalPositiveId(-1));

        self::assertTrue(RestController::validateListLimit(1));
        self::assertTrue(RestController::validateListLimit(200));
        self::assertFalse(RestController::validateListLimit(0));
        self::assertFalse(RestController::validateListLimit(201));
    }

    public function test_save_provider_rejects_non_json_payload_before_repository_write(): void
    {
        $controller = $this->controllerWithoutConstructor();
        $request = new WP_REST_Request([], null);

        $result = $controller->saveProvider($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_payload', $result->get_error_code());
    }

    public function test_save_provider_rejects_unsupported_fields_before_repository_write(): void
    {
        $controller = $this->controllerWithoutConstructor();
        $request = new WP_REST_Request(
            [],
            [
                'name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'unexpected' => 'value',
            ]
        );

        $result = $controller->saveProvider($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_provider_fields', $result->get_error_code());
        self::assertSame(['unexpected'], $result->get_error_data()['fields'] ?? []);
    }

    public function test_save_provider_rejects_unsupported_adapter_before_repository_write(): void
    {
        $controller = $this->controllerWithoutConstructor();
        $request = new WP_REST_Request(
            [],
            [
                'name' => 'Primary SMTP',
                'adapter_type' => 'unknown',
            ]
        );

        $result = $controller->saveProvider($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('invalid_provider_type', $result->get_error_code());
    }

    public function test_list_providers_returns_safe_provider_config(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 1,
                'slug' => 'primary',
                'name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'priority' => 1,
                'weight' => 1,
                'is_active' => 1,
                'circuit_state' => 'closed',
                'config_json' => wp_json_encode(
                    [
                        'host' => 'smtp.example.test',
                        'password' => 'plain-password',
                        'api_key' => 'plain-api-key',
                        'apikey' => 'plain-apikey',
                        'nested' => [
                            'access_token' => 'plain-token',
                        ],
                    ]
                ),
            ],
        ];

        $controller = $this->controllerWithoutConstructor();
        $this->setControllerProperty($controller, 'providers', new ProviderRepository());

        $response = $controller->listProviders();
        $provider = $response->data['providers'][0];

        self::assertArrayNotHasKey('config_json', $provider);
        self::assertSame('smtp.example.test', $provider['config']['host']);
        self::assertSame('[REDACTED]', $provider['config']['password']);
        self::assertSame('[REDACTED]', $provider['config']['api_key']);
        self::assertSame('[REDACTED]', $provider['config']['apikey']);
        self::assertSame('[REDACTED]', $provider['config']['nested']['access_token']);
        self::assertStringNotContainsString('plain-password', wp_json_encode($response->data));
        self::assertStringNotContainsString('plain-api-key', wp_json_encode($response->data));
    }

    private function controllerWithoutConstructor(): RestController
    {
        $reflection = new \ReflectionClass(RestController::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    private function setControllerProperty(RestController $controller, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty(RestController::class, $property);
        $reflection->setValue($controller, $value);
    }
}
