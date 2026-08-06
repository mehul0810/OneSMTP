<?php

declare(strict_types=1);

namespace OneSMTP\Api;

use OneSMTP\Core\Capabilities;
use OneSMTP\Pipeline\SendPipeline;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Providers\ProviderTestService;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Settings\SenderIdentity;
use OneSMTP\Settings\SenderIdentityRepository;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController
{
    private ProviderRepository $providers;
    private MessageRepository $messages;
    private AttemptRepository $attempts;
    private SendPipeline $pipeline;
    private ProviderAdapterRegistry $registry;
    private ProviderDeliveryManager $deliveryManager;
    private SenderIdentityRepository $senderIdentity;
    private ProviderTestService $providerTests;

    public function __construct(
        ProviderRepository $providers,
        MessageRepository $messages,
        AttemptRepository $attempts,
        SendPipeline $pipeline,
        ?ProviderAdapterRegistry $registry = null,
        ?SenderIdentityRepository $senderIdentity = null,
        ?ProviderTestService $providerTests = null
    ) {
        $this->providers = $providers;
        $this->messages = $messages;
        $this->attempts = $attempts;
        $this->pipeline = $pipeline;
        $this->registry = $registry ?? new ProviderAdapterRegistry();
        $this->deliveryManager = new ProviderDeliveryManager($this->registry);
        $this->senderIdentity = $senderIdentity ?? new SenderIdentityRepository();
        $this->providerTests = $providerTests ?? new ProviderTestService(
            $messages,
            $attempts,
            new EventRepository(),
            $this->deliveryManager,
            $this->senderIdentity
        );
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'onesmtp/v1',
            '/providers',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'listProviders'],
                    'permission_callback' => [self::class, 'canManage'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'saveProvider'],
                    'permission_callback' => [self::class, 'canManage'],
                    'args' => self::providerRequestArgs(),
                ],
            ]
        );

        register_rest_route(
            'onesmtp/v1',
            '/providers/(?P<id>\d+)',
            [
                [
                    'methods' => WP_REST_Server::EDITABLE,
                    'callback' => [$this, 'saveProvider'],
                    'permission_callback' => [self::class, 'canManage'],
                    'args' => array_merge(self::idRequestArgs(), self::providerRequestArgs()),
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'deleteProvider'],
                    'permission_callback' => [self::class, 'canManage'],
                    'args' => self::idRequestArgs(),
                ],
            ]
        );

        register_rest_route(
            'onesmtp/v1',
            '/providers/(?P<id>\d+)/test',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'testProvider'],
                    'permission_callback' => [self::class, 'canManage'],
                    'args' => array_merge(self::idRequestArgs(), self::testEmailRequestArgs()),
                ],
            ]
        );

        register_rest_route(
            'onesmtp/v1',
            '/messages',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'listMessages'],
                    'permission_callback' => [self::class, 'canViewLogs'],
                    'args' => [
                        'limit' => [
                            'type' => 'integer',
                            'required' => false,
                            'default' => 50,
                            'minimum' => 1,
                            'maximum' => 200,
                            'validate_callback' => [self::class, 'validateListLimit'],
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            'onesmtp/v1',
            '/messages/(?P<id>\d+)/attempts',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'listAttempts'],
                    'permission_callback' => [self::class, 'canViewLogs'],
                    'args' => self::idRequestArgs(),
                ],
            ]
        );

        register_rest_route(
            'onesmtp/v1',
            '/messages/(?P<id>\d+)/resend',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'resendMessage'],
                    'permission_callback' => [self::class, 'canResend'],
                    'args' => array_merge(
                        self::idRequestArgs(),
                        [
                            'provider_id' => [
                                'type' => 'integer',
                                'required' => false,
                                'minimum' => 1,
                                'validate_callback' => [self::class, 'validateOptionalPositiveId'],
                            ],
                        ]
                    ),
                ],
            ]
        );

        register_rest_route(
            'onesmtp/v1',
            '/settings/sender-identity',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'getSenderIdentity'],
                    'permission_callback' => [self::class, 'canManage'],
                ],
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'saveSenderIdentity'],
                    'permission_callback' => [self::class, 'canManage'],
                    'args' => self::senderIdentityRequestArgs(),
                ],
            ]
        );
    }

    public function listProviders(): WP_REST_Response
    {
        return new WP_REST_Response(['providers' => $this->providers->getAllSafe()]);
    }

    public function saveProvider(WP_REST_Request $request)
    {
        $payload = $this->normalizeProviderPayload($request);
        if ($payload instanceof WP_Error) {
            return $payload;
        }

        $id = (int) $request->get_param('id');
        if ($id > 0) {
            $existing = $this->providers->find($id);
            if (! is_array($existing)) {
                return new WP_Error('missing_provider', 'Provider not found.', ['status' => 404]);
            }

            $payload['id'] = $id;
            if (! isset($payload['slug'])) {
                $payload['slug'] = sanitize_key((string) ($existing['slug'] ?? ''));
            }
        }

        $savedId = $this->providers->save($payload);
        if ($savedId <= 0) {
            return new WP_Error('provider_save_failed', 'Unable to save provider.', ['status' => 422]);
        }

        $provider = $this->providers->findSafe($savedId);

        return new WP_REST_Response(['provider' => $provider], 201);
    }

    public function deleteProvider(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return new WP_Error('invalid_provider', 'Invalid provider id.', ['status' => 400]);
        }

        $deleted = $this->providers->delete($id);
        if (! $deleted) {
            return new WP_Error('provider_delete_failed', 'Unable to delete provider.', ['status' => 422]);
        }

        return new WP_REST_Response(['deleted' => true]);
    }

    public function testProvider(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return new WP_Error('invalid_provider', 'Invalid provider id.', ['status' => 400]);
        }

        $provider = $this->providers->find($id);
        if (! is_array($provider)) {
            return new WP_Error('missing_provider', 'Provider not found.', ['status' => 404]);
        }

        $payload = $this->normalizeTestEmailPayload($request);
        if ($payload instanceof WP_Error) {
            return $payload;
        }

        $test = $this->providerTests->send($provider, $payload);
        $result = $test['result'];
        $providerId = (int) ($provider['id'] ?? $id);
        $adapterType = sanitize_key((string) ($provider['adapter_type'] ?? ''));

        return new WP_REST_Response(
            [
                'ok' => $result->isSuccess(),
                'code' => $result->getCode(),
                'message' => $result->getMessage(),
                'test' => [
                    'provider_id' => $providerId,
                    'adapter_type' => $adapterType,
                    'to' => $payload['to'],
                    'message_id' => (int) $test['message_id'],
                    'simulated' => (bool) $test['simulated'],
                ],
            ],
            $result->isSuccess() ? 200 : 422
        );
    }

    public function listMessages(WP_REST_Request $request): WP_REST_Response
    {
        $limit = max(1, min(200, (int) $request->get_param('limit')));
        if ($limit <= 0) {
            $limit = 50;
        }

        return new WP_REST_Response(['messages' => $this->messages->listRecent($limit)]);
    }

    public function listAttempts(WP_REST_Request $request): WP_REST_Response
    {
        $messageId = (int) $request->get_param('id');
        if ($messageId <= 0) {
            return new WP_REST_Response(['attempts' => []]);
        }

        return new WP_REST_Response(['attempts' => $this->attempts->listByMessageId($messageId)]);
    }

    public function resendMessage(WP_REST_Request $request)
    {
        $messageId = (int) $request->get_param('id');
        if ($messageId <= 0) {
            return new WP_Error('invalid_message', 'Invalid message id.', ['status' => 400]);
        }

        $providerId = (int) $request->get_param('provider_id');
        $ok = $this->pipeline->resendMessage($messageId, $providerId > 0 ? $providerId : null);
        if (! $ok) {
            return new WP_Error('resend_failed', 'Resend failed.', ['status' => 422]);
        }

        return new WP_REST_Response(['resent' => true, 'message_id' => $messageId, 'provider_id' => $providerId], 200);
    }

    public function getSenderIdentity(): WP_REST_Response
    {
        return new WP_REST_Response(['identity' => $this->senderIdentity->get()->toArray()]);
    }

    public function saveSenderIdentity(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            return new WP_Error('invalid_payload', 'Request body must be JSON.', ['status' => 400]);
        }

        $allowed = array_keys(self::senderIdentityRequestArgs());
        $unknown = array_values(array_diff(array_keys($payload), $allowed));
        if ($unknown !== []) {
            return new WP_Error(
                'invalid_sender_identity_fields',
                'Sender identity payload contains unsupported fields.',
                ['status' => 400, 'fields' => $unknown]
            );
        }

        $current = $this->senderIdentity->get()->toArray();
        $normalized = $current;

        if (array_key_exists('from_email', $payload)) {
            $normalized['from_email'] = sanitize_email((string) $payload['from_email']);
        }

        if (array_key_exists('from_name', $payload)) {
            $normalized['from_name'] = sanitize_text_field((string) $payload['from_name']);
        }

        try {
            $identity = SenderIdentity::fromArray($normalized);
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('invalid_sender_identity', $exception->getMessage(), ['status' => 400]);
        }

        if (! $this->senderIdentity->saveAuthorized($identity)) {
            return new WP_Error('sender_identity_save_failed', 'Unable to save sender identity.', ['status' => 422]);
        }

        return new WP_REST_Response(['identity' => $identity->toArray()], 200);
    }

    public static function validatePositiveId(mixed $value): bool
    {
        return is_numeric($value) && (int) $value > 0;
    }

    public static function validateOptionalPositiveId(mixed $value): bool
    {
        return $value === null || $value === '' || self::validatePositiveId($value);
    }

    public static function validateListLimit(mixed $value): bool
    {
        return is_numeric($value) && (int) $value >= 1 && (int) $value <= 200;
    }

    public static function canManage(): bool
    {
        return Capabilities::canManage();
    }

    public static function canViewLogs(): bool
    {
        return Capabilities::canViewLogs();
    }

    public static function canResend(): bool
    {
        return Capabilities::canResendEmails();
    }

    private static function idRequestArgs(): array
    {
        return [
            'id' => [
                'type' => 'integer',
                'required' => true,
                'minimum' => 1,
                'validate_callback' => [self::class, 'validatePositiveId'],
            ],
        ];
    }

    private static function providerRequestArgs(): array
    {
        return [
            'slug' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_key',
            ],
            'name' => [
                'type' => 'string',
                'required' => true,
                'minLength' => 1,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'adapter_type' => [
                'type' => 'string',
                'required' => true,
                'enum' => ProviderTypes::all(),
                'sanitize_callback' => 'sanitize_key',
            ],
            'priority' => [
                'type' => 'integer',
                'required' => false,
                'minimum' => 1,
            ],
            'weight' => [
                'type' => 'integer',
                'required' => false,
                'minimum' => 1,
            ],
            'is_active' => [
                'type' => 'boolean',
                'required' => false,
            ],
            'config' => [
                'type' => 'object',
                'required' => false,
            ],
        ];
    }

    private static function testEmailRequestArgs(): array
    {
        return [
            'to' => [
                'type' => 'string',
                'required' => true,
                'sanitize_callback' => 'sanitize_email',
            ],
            'subject' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'message' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'body' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
        ];
    }

    private static function senderIdentityRequestArgs(): array
    {
        return [
            'from_email' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_email',
            ],
            'from_name' => [
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    private function normalizeProviderPayload(WP_REST_Request $request): array|WP_Error
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            return new WP_Error('invalid_payload', 'Request body must be JSON.', ['status' => 400]);
        }

        $allowed = array_keys(self::providerRequestArgs());
        $unknown = array_values(array_diff(array_keys($payload), $allowed));
        if ($unknown !== []) {
            return new WP_Error(
                'invalid_provider_fields',
                'Provider payload contains unsupported fields.',
                ['status' => 400, 'fields' => $unknown]
            );
        }

        $adapterType = isset($payload['adapter_type']) ? sanitize_key((string) $payload['adapter_type']) : '';
        if (! ProviderTypes::isSupported($adapterType)) {
            return new WP_Error('invalid_provider_type', 'Provider adapter type is not supported.', ['status' => 400]);
        }

        $name = isset($payload['name']) ? trim((string) $payload['name']) : '';
        if ($name === '') {
            return new WP_Error('invalid_provider_name', 'Provider name is required.', ['status' => 400]);
        }

        $normalized = [
            'adapter_type' => $adapterType,
            'name' => sanitize_text_field($name),
        ];

        if (isset($payload['slug']) && trim((string) $payload['slug']) !== '') {
            $normalized['slug'] = sanitize_key((string) $payload['slug']);
        }

        if (isset($payload['priority'])) {
            if (! self::validatePositiveId($payload['priority'])) {
                return new WP_Error('invalid_provider_priority', 'Provider priority must be a positive integer.', ['status' => 400]);
            }

            $normalized['priority'] = (int) $payload['priority'];
        }

        if (isset($payload['weight'])) {
            if (! self::validatePositiveId($payload['weight'])) {
                return new WP_Error('invalid_provider_weight', 'Provider weight must be a positive integer.', ['status' => 400]);
            }

            $normalized['weight'] = (int) $payload['weight'];
        }

        if (array_key_exists('is_active', $payload)) {
            $normalized['is_active'] = filter_var($payload['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalized['is_active'] === null) {
                return new WP_Error('invalid_provider_status', 'Provider active state must be boolean.', ['status' => 400]);
            }
        }

        if (array_key_exists('config', $payload)) {
            if (! is_array($payload['config'])) {
                return new WP_Error('invalid_provider_config', 'Provider config must be an object.', ['status' => 400]);
            }

            $normalized['config'] = $this->normalizeProviderConfig($payload['config']);
        }

        return $normalized;
    }

    private function normalizeProviderConfig(array $config): array
    {
        $normalized = [];

        foreach ($config as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '' || is_array($value) || is_object($value)) {
                continue;
            }

            if (is_bool($value) || is_numeric($value)) {
                $normalized[$key] = $value;
                continue;
            }

            $normalized[$key] = sanitize_text_field((string) $value);
        }

        return $normalized;
    }

    private function normalizeTestEmailPayload(WP_REST_Request $request): array|WP_Error
    {
        $to = sanitize_email((string) $request->get_param('to'));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return new WP_Error('invalid_test_recipient', 'A valid recipient email address is required.', ['status' => 400]);
        }

        $subject = sanitize_text_field((string) ($request->get_param('subject') ?? ''));
        if ($subject === '') {
            $subject = '[Aculect Mail] Test email';
        }

        $message = (string) ($request->get_param('message') ?? '');
        if ($message === '') {
            $message = (string) ($request->get_param('body') ?? '');
        }

        $message = sanitize_textarea_field($message);
        if ($message === '') {
            $message = 'This is a test email sent by Aculect Mail.';
        }

        return [
            'to' => [$to],
            'subject' => $subject,
            'message' => $message,
            'headers' => [],
            'meta' => [
                'source' => 'rest_test_email',
            ],
        ];
    }
}
