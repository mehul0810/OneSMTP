<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Api;

use OneSMTP\Api\RestController;
use OneSMTP\Core\Capabilities;
use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Providers\SendResult;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\SecretVault;
use OneSMTP\Settings\SenderIdentityRepository;
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
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_transients'] = [];
        unset($GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_current_user_can']);
    }

    public function test_register_routes_adds_validation_arguments(): void
    {
        $controller = $this->controllerWithoutConstructor();

        $controller->registerRoutes();

        $routes = $GLOBALS['onesmtp_test_rest_routes'];
        self::assertCount(7, $routes);

        $providerWriteRoute = $routes[0]['args'][1];
        self::assertArrayHasKey('args', $providerWriteRoute);
        self::assertArrayHasKey('adapter_type', $providerWriteRoute['args']);
        self::assertSame(ProviderTypes::all(), $providerWriteRoute['args']['adapter_type']['enum']);

        $testRoute = $routes[2]['args'][0];
        self::assertSame([RestController::class, 'canManage'], $testRoute['permission_callback']);
        self::assertArrayHasKey('id', $testRoute['args']);
        self::assertArrayHasKey('to', $testRoute['args']);
        self::assertArrayHasKey('subject', $testRoute['args']);
        self::assertArrayHasKey('message', $testRoute['args']);
        self::assertArrayHasKey('body', $testRoute['args']);

        $messagesRoute = $routes[3]['args'][0];
        self::assertArrayHasKey('limit', $messagesRoute['args']);
        self::assertSame(200, $messagesRoute['args']['limit']['maximum']);

        $resendRoute = $routes[5]['args'][0];
        self::assertArrayHasKey('id', $resendRoute['args']);
        self::assertArrayHasKey('provider_id', $resendRoute['args']);

        $senderIdentityRoute = $routes[6]['args'][1];
        self::assertSame([RestController::class, 'canManage'], $senderIdentityRoute['permission_callback']);
        self::assertArrayHasKey('from_email', $senderIdentityRoute['args']);
        self::assertArrayHasKey('from_name', $senderIdentityRoute['args']);
    }

    /**
     * @dataProvider sensitiveRoutePermissionProvider
     *
     * @param array{0:class-string,1:string} $expectedCallback
     */
    public function test_sensitive_route_permissions_reject_unauthenticated_and_low_privilege_users(
        string $route,
        int $operationIndex,
        array $expectedCallback,
        string $requiredCapability
    ): void {
        $operation = $this->registeredRouteOperation($route, $operationIndex);

        self::assertSame($expectedCallback, $operation['permission_callback']);

        $this->setCurrentUserCaps([]);
        self::assertFalse($this->callPermissionCallback($operation['permission_callback']));

        $this->setCurrentUserCaps(['read' => true, $requiredCapability => false, 'manage_options' => false]);
        self::assertFalse($this->callPermissionCallback($operation['permission_callback']));
    }

    /**
     * @dataProvider sensitiveRoutePermissionProvider
     *
     * @param array{0:class-string,1:string} $expectedCallback
     */
    public function test_sensitive_route_permissions_allow_route_capability_and_manage_options(
        string $route,
        int $operationIndex,
        array $expectedCallback,
        string $requiredCapability
    ): void {
        $operation = $this->registeredRouteOperation($route, $operationIndex);

        self::assertSame($expectedCallback, $operation['permission_callback']);

        $this->setCurrentUserCaps([$requiredCapability => true]);
        self::assertTrue($this->callPermissionCallback($operation['permission_callback']));

        $this->setCurrentUserCaps(['manage_options' => true]);
        self::assertTrue($this->callPermissionCallback($operation['permission_callback']));
    }

    /**
     * @return array<string,array{0:string,1:int,2:array{0:class-string,1:string},3:string}>
     */
    public static function sensitiveRoutePermissionProvider(): array
    {
        return [
            'provider list' => [
                '/providers',
                0,
                [RestController::class, 'canManage'],
                Capabilities::MANAGE_PLUGIN,
            ],
            'provider create' => [
                '/providers',
                1,
                [RestController::class, 'canManage'],
                Capabilities::MANAGE_PLUGIN,
            ],
            'provider update' => [
                '/providers/(?P<id>\d+)',
                0,
                [RestController::class, 'canManage'],
                Capabilities::MANAGE_PLUGIN,
            ],
            'provider delete' => [
                '/providers/(?P<id>\d+)',
                1,
                [RestController::class, 'canManage'],
                Capabilities::MANAGE_PLUGIN,
            ],
            'provider test send' => [
                '/providers/(?P<id>\d+)/test',
                0,
                [RestController::class, 'canManage'],
                Capabilities::MANAGE_PLUGIN,
            ],
            'message log list' => [
                '/messages',
                0,
                [RestController::class, 'canViewLogs'],
                Capabilities::VIEW_LOGS,
            ],
            'message attempts' => [
                '/messages/(?P<id>\d+)/attempts',
                0,
                [RestController::class, 'canViewLogs'],
                Capabilities::VIEW_LOGS,
            ],
            'message resend' => [
                '/messages/(?P<id>\d+)/resend',
                0,
                [RestController::class, 'canResend'],
                Capabilities::RESEND_EMAILS,
            ],
            'sender identity read' => [
                '/settings/sender-identity',
                0,
                [RestController::class, 'canManage'],
                Capabilities::MANAGE_PLUGIN,
            ],
            'sender identity write' => [
                '/settings/sender-identity',
                1,
                [RestController::class, 'canManage'],
                Capabilities::MANAGE_PLUGIN,
            ],
        ];
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

    public function test_free_rest_provider_updates_do_not_activate_pro_only_budget_fields(): void
    {
        $controller = $this->controllerWithoutConstructor();
        $this->setControllerProperty($controller, 'providers', new ProviderRepository());
        $GLOBALS['wpdb'] = new FakeWpdb();

        $response = $controller->saveProvider(new WP_REST_Request([], [
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'config' => [
                'host' => 'smtp.example.test',
                'quota_per_minute' => 10,
                'quota_per_hour' => 20,
                'quota_per_day' => 30,
            ],
        ]));

        self::assertSame(201, $response->status);
        $config = json_decode((string) $GLOBALS['wpdb']->inserts[0]['data']['config_json'], true);
        self::assertSame('smtp.example.test', $config['host']);
        self::assertArrayNotHasKey('quota_per_minute', $config);
        self::assertArrayNotHasKey('quota_per_hour', $config);
        self::assertArrayNotHasKey('quota_per_day', $config);
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

    public function test_save_provider_updates_an_existing_connection_without_replacing_its_omitted_credentials(): void
    {
        $vault = new SecretVault();
        $storedPassword = $vault->encrypt('existing-password');
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->providerRowsById[7] = [
            'id' => 7,
            'slug' => 'primary_smtp',
            'name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'priority' => 10,
            'weight' => 1,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode([
                'host' => 'smtp.old.test',
                'password' => $storedPassword,
            ]),
        ];

        $controller = $this->controllerWithoutConstructor();
        $this->setControllerProperty($controller, 'providers', new ProviderRepository());

        $response = $controller->saveProvider(new WP_REST_Request(
            ['id' => 7],
            [
                'name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'priority' => 5,
                'weight' => 2,
                'is_active' => true,
                'config' => ['host' => 'smtp.new.test'],
            ]
        ));

        self::assertSame(201, $response->status);
        self::assertCount(1, $GLOBALS['wpdb']->updates);
        self::assertSame('primary_smtp', $GLOBALS['wpdb']->updates[0]['data']['slug']);

        $updatedConfig = json_decode((string) $GLOBALS['wpdb']->updates[0]['data']['config_json'], true);
        self::assertIsArray($updatedConfig);
        self::assertSame('smtp.new.test', $updatedConfig['host']);
        self::assertSame('existing-password', $vault->decrypt((string) $updatedConfig['password']));
        self::assertSame(5, $GLOBALS['wpdb']->updates[0]['data']['priority']);
        self::assertSame(2, $GLOBALS['wpdb']->updates[0]['data']['weight']);
        self::assertSame(1, $GLOBALS['wpdb']->updates[0]['data']['is_active']);
    }

    public function test_provider_test_email_sends_through_selected_adapter_with_safe_response(): void
    {
        $adapter = new TestEmailAdapter(new SendResult(true, 'accepted', 'Accepted by provider.', 'provider-message-id'));
        $controller = $this->controllerWithProviders(
            [
                7 => [
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
                            'api_key' => 'secret-api-key',
                            'timeout' => 10,
                            'from_email' => 'provider@example.test',
                            'from_name' => 'Provider Sender',
                        ]
                    ),
                ],
            ],
            ['sendgrid' => $adapter]
        );

        $request = new WP_REST_Request(
            ['id' => 7, 'to' => 'recipient@example.test', 'subject' => '  Test <b>subject</b>  '],
            null
        );

        $response = $controller->testProvider($request);

        self::assertSame(200, $response->status);
        self::assertTrue($response->data['ok']);
        self::assertSame('accepted', $response->data['code']);
        self::assertSame('Accepted by provider.', $response->data['message']);
        self::assertSame(
            [
                'provider_id' => 7,
                'adapter_type' => 'sendgrid',
                'to' => ['recipient@example.test'],
                'message_id' => 1,
                'simulated' => false,
            ],
            $response->data['test']
        );
        self::assertSame(['recipient@example.test'], $adapter->lastMessage['to'] ?? []);
        self::assertSame('Test subject', $adapter->lastMessage['subject'] ?? '');
        self::assertSame('This is a test email sent by Aculect Mail.', $adapter->lastMessage['message'] ?? '');
        self::assertSame('secret-api-key', $adapter->lastConfig['api_key'] ?? null);
        self::assertContains('From: Provider Sender <provider@example.test>', $adapter->lastMessage['headers'] ?? []);
        self::assertNotNull($this->findInsert('onesmtp_attempts'));
        self::assertNotNull($this->findUpdate('onesmtp_messages', 'sent'));
        self::assertStringNotContainsString('secret-api-key', wp_json_encode($response->data));
        self::assertStringNotContainsString('provider-message-id', wp_json_encode($response->data));
    }

    public function test_provider_test_is_logged_as_simulated_without_contacting_adapter(): void
    {
        update_option('onesmtp_settings', ['simulation_mode' => ['enabled' => true]], false);
        $adapter = new TestEmailAdapter(new SendResult(true, 'accepted', 'Must not run.'));
        $controller = $this->controllerWithProviders([
            8 => [
                'id' => 8,
                'slug' => 'simulated',
                'name' => 'Simulated provider',
                'adapter_type' => 'sendgrid',
                'priority' => 1,
                'weight' => 1,
                'is_active' => 1,
                'circuit_state' => 'closed',
                'config_json' => wp_json_encode(['api_key' => 'secret']),
            ],
        ], ['sendgrid' => $adapter]);

        $response = $controller->testProvider(new WP_REST_Request(['id' => 8, 'to' => 'recipient@example.test'], null));

        self::assertSame(200, $response->status);
        self::assertTrue($response->data['ok']);
        self::assertTrue($response->data['test']['simulated']);
        self::assertSame('simulated', $response->data['code']);
        self::assertNull($adapter->lastMessage);
        self::assertNull($this->findInsert('onesmtp_attempts'));
        self::assertNotNull($this->findUpdate('onesmtp_messages', 'simulated'));
    }

    public function test_provider_test_email_returns_safe_failure_details(): void
    {
        $adapter = new TestEmailAdapter(new SendResult(false, 'sendgrid_api_error', 'Provider rejected the message.'));
        $controller = $this->controllerWithProviders(
            [
                9 => [
                    'id' => 9,
                    'slug' => 'primary',
                    'name' => 'Primary API',
                    'adapter_type' => 'sendgrid',
                    'priority' => 1,
                    'weight' => 1,
                    'is_active' => 1,
                    'circuit_state' => 'closed',
                    'config_json' => wp_json_encode(['api_key' => 'secret-api-key']),
                ],
            ],
            ['sendgrid' => $adapter]
        );

        $response = $controller->testProvider(new WP_REST_Request(['id' => 9, 'to' => 'recipient@example.test'], null));

        self::assertSame(422, $response->status);
        self::assertFalse($response->data['ok']);
        self::assertSame('sendgrid_api_error', $response->data['code']);
        self::assertSame('Provider rejected the message.', $response->data['message']);
        self::assertArrayNotHasKey('config', $response->data);
        self::assertStringNotContainsString('secret-api-key', wp_json_encode($response->data));
    }

    public function test_provider_test_email_rejects_missing_provider_invalid_recipient_and_missing_adapter(): void
    {
        $controller = $this->controllerWithProviders(
            [
                12 => [
                    'id' => 12,
                    'slug' => 'unsupported',
                    'name' => 'Unsupported',
                    'adapter_type' => 'unknown',
                    'priority' => 1,
                    'weight' => 1,
                    'is_active' => 1,
                    'circuit_state' => 'closed',
                    'config_json' => wp_json_encode([]),
                ],
            ],
            []
        );

        $missing = $controller->testProvider(new WP_REST_Request(['id' => 99, 'to' => 'recipient@example.test'], null));
        self::assertInstanceOf(WP_Error::class, $missing);
        self::assertSame('missing_provider', $missing->get_error_code());

        $invalidRecipient = $controller->testProvider(new WP_REST_Request(['id' => 12, 'to' => 'not-an-email'], null));
        self::assertInstanceOf(WP_Error::class, $invalidRecipient);
        self::assertSame('invalid_test_recipient', $invalidRecipient->get_error_code());

        $missingAdapter = $controller->testProvider(new WP_REST_Request(['id' => 12, 'to' => 'recipient@example.test'], null));
        self::assertSame(422, $missingAdapter->status);
        self::assertFalse($missingAdapter->data['ok']);
        self::assertSame('adapter_missing', $missingAdapter->data['code']);
        self::assertSame('unknown', $missingAdapter->data['test']['adapter_type']);
    }

    public function test_sender_identity_can_be_read_and_saved_without_exposing_other_settings(): void
    {
        $GLOBALS['onesmtp_test_options']['onesmtp_settings'] = [
            'value' => [
                'sender_identity' => [
                    'from_email' => 'old@example.test',
                    'from_name' => 'Old Sender',
                    'reply_to' => ['reply@example.test'],
                ],
                'rate_limits' => ['per_minute' => 20],
            ],
            'autoload' => false,
        ];

        $controller = $this->controllerWithoutConstructor();
        $this->setControllerProperty($controller, 'senderIdentity', new SenderIdentityRepository());

        $current = $controller->getSenderIdentity();
        self::assertSame('old@example.test', $current->data['identity']['from_email']);

        $saved = $controller->saveSenderIdentity(new WP_REST_Request([], [
            'from_email' => 'new@example.test',
            'from_name' => 'New Sender',
        ]));

        self::assertSame(200, $saved->status);
        self::assertSame('new@example.test', $saved->data['identity']['from_email']);
        self::assertSame('New Sender', $saved->data['identity']['from_name']);
        self::assertSame(['reply@example.test'], $saved->data['identity']['reply_to']);
        self::assertSame(['per_minute' => 20], $GLOBALS['onesmtp_test_options']['onesmtp_settings']['value']['rate_limits']);
    }

    public function test_sender_identity_rejects_unknown_fields_and_invalid_email(): void
    {
        $controller = $this->controllerWithoutConstructor();
        $this->setControllerProperty($controller, 'senderIdentity', new SenderIdentityRepository());

        $unknown = $controller->saveSenderIdentity(new WP_REST_Request([], [
            'from_email' => 'sender@example.test',
            'unexpected' => 'value',
        ]));
        self::assertInstanceOf(WP_Error::class, $unknown);
        self::assertSame('invalid_sender_identity_fields', $unknown->get_error_code());

        $invalid = $controller->saveSenderIdentity(new WP_REST_Request([], [
            'from_email' => 'not-an-email',
        ]));
        self::assertInstanceOf(WP_Error::class, $invalid);
        self::assertSame('invalid_sender_identity', $invalid->get_error_code());
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

    /**
     * @return array<string,mixed>
     */
    private function registeredRouteOperation(string $route, int $operationIndex): array
    {
        $controller = $this->controllerWithoutConstructor();
        $controller->registerRoutes();

        foreach ($GLOBALS['onesmtp_test_rest_routes'] as $registeredRoute) {
            if ($registeredRoute['route'] !== $route) {
                continue;
            }

            return $registeredRoute['args'][$operationIndex];
        }

        self::fail(sprintf('REST route %s was not registered.', $route));
    }

    /**
     * @param array<string,bool> $caps
     */
    private function setCurrentUserCaps(array $caps): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'] = $caps;
    }

    private function callPermissionCallback(callable $callback): bool
    {
        return (bool) $callback();
    }

    /**
     * @param array<int,array<string,mixed>> $providers
     * @param array<string,ProviderAdapterInterface> $adapters
     */
    private function controllerWithProviders(array $providers, array $adapters): RestController
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->providerRowsById = $providers;

        return new RestController(
            new ProviderRepository(),
            new \OneSMTP\Repository\MessageRepository(),
            new \OneSMTP\Repository\AttemptRepository(),
            $this->instanceWithoutConstructor(\OneSMTP\Pipeline\SendPipeline::class),
            new ProviderAdapterRegistry($adapters)
        );
    }

    private function findInsert(string $tableSuffix): ?array
    {
        foreach ($GLOBALS['wpdb']->inserts as $insert) {
            if (str_ends_with($insert['table'], $tableSuffix)) {
                return $insert;
            }
        }

        return null;
    }

    private function findUpdate(string $tableSuffix, string $status): ?array
    {
        foreach ($GLOBALS['wpdb']->updates as $update) {
            if (str_ends_with($update['table'], $tableSuffix) && ($update['data']['status'] ?? '') === $status) {
                return $update;
            }
        }

        return null;
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    private function instanceWithoutConstructor(string $className): object
    {
        $reflection = new \ReflectionClass($className);

        return $reflection->newInstanceWithoutConstructor();
    }
}

final class TestEmailAdapter implements ProviderAdapterInterface
{
    /** @var array<string,mixed>|null */
    public ?array $lastMessage = null;

    /** @var array<string,mixed>|null */
    public ?array $lastConfig = null;

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
        return new SendResult(false, 'wrong_path', 'testConnection should not be used for test emails.');
    }
}
