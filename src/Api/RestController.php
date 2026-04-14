<?php

declare(strict_types=1);

namespace OneSMTP\Api;

use OneSMTP\Core\Capabilities;
use OneSMTP\Pipeline\SendPipeline;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
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

    public function __construct(
        ProviderRepository $providers,
        MessageRepository $messages,
        AttemptRepository $attempts,
        SendPipeline $pipeline,
        ?ProviderAdapterRegistry $registry = null
    ) {
        $this->providers = $providers;
        $this->messages = $messages;
        $this->attempts = $attempts;
        $this->pipeline = $pipeline;
        $this->registry = $registry ?? new ProviderAdapterRegistry();
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
                ],
                [
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => [$this, 'deleteProvider'],
                    'permission_callback' => [self::class, 'canManage'],
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
                ],
            ]
        );
    }

    public function listProviders(): WP_REST_Response
    {
        return new WP_REST_Response(['providers' => $this->providers->getAll()]);
    }

    public function saveProvider(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            return new WP_Error('invalid_payload', 'Request body must be JSON.', ['status' => 400]);
        }

        $id = (int) $request->get_param('id');
        if ($id > 0) {
            $payload['id'] = $id;
        }

        $savedId = $this->providers->save($payload);
        if ($savedId <= 0) {
            return new WP_Error('provider_save_failed', 'Unable to save provider.', ['status' => 422]);
        }

        $provider = $this->providers->find($savedId);

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

        $adapterType = (string) ($provider['adapter_type'] ?? '');
        $adapter = $this->registry->get($adapterType);
        if ($adapter === null) {
            return new WP_Error('unsupported_provider', 'Unsupported provider adapter.', ['status' => 422]);
        }

        $result = $adapter->testConnection(new ProviderConfig((array) ($provider['config'] ?? [])));

        return new WP_REST_Response(
            [
                'ok' => $result->isSuccess(),
                'code' => $result->getCode(),
                'message' => $result->getMessage(),
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
}
