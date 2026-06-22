<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Api;

use OneSMTP\Api\RestController;
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

    private function controllerWithoutConstructor(): RestController
    {
        $reflection = new \ReflectionClass(RestController::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
