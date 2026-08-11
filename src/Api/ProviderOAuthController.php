<?php

declare(strict_types=1);

namespace OneSMTP\Api;

use OneSMTP\Core\Capabilities;
use OneSMTP\Providers\Auth\ProviderOAuthLifecycleCoordinator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Capability- and REST-nonce-protected local OAuth lifecycle routes. */
final class ProviderOAuthController
{
    public function __construct(private ProviderOAuthLifecycleCoordinator $coordinator)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route('onesmtp/v1', '/providers/(?P<id>\d+)/oauth/start', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'start'],
                'permission_callback' => [$this, 'canManageMutation'],
            ],
        ]);
        register_rest_route('onesmtp/v1', '/providers/(?P<id>\d+)/oauth/callback', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'callback'],
                'permission_callback' => [$this, 'canManageCallback'],
            ],
        ]);
        register_rest_route('onesmtp/v1', '/providers/(?P<id>\d+)/oauth/status', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'status'],
                'permission_callback' => [$this, 'canManageCallback'],
            ],
        ]);
        register_rest_route('onesmtp/v1', '/providers/(?P<id>\d+)/oauth/disconnect', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'disconnect'],
                'permission_callback' => [$this, 'canManageMutation'],
            ],
        ]);
    }

    public function start(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = $this->coordinator->begin(
            (int) $request->get_param('id'),
            (string) ($request->get_param('return_url') ?? '')
        );

        return $this->response($result, ($result['ok'] ?? false) === true ? 200 : 422);
    }

    public function callback(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = [];
        foreach ([ 'state', 'code', 'error' ] as $key) {
            $value = $request->get_param($key);
            if (is_scalar($value)) {
                $params[ $key ] = (string) $value;
            }
        }
        $result = $this->coordinator->callback( (int) $request->get_param('id'), $params);
        $status = ($result['ok'] ?? false) === true ? 200 : 422;
        $target = isset($result['return_target']) ? (string) $result['return_target'] : '';
        if ($target !== '' && function_exists('wp_safe_redirect')) {
            wp_safe_redirect(add_query_arg('onesmtp_oauth', (string) ($result['code'] ?? 'error'), $target));
            $status = 302;
        }

        return $this->response($result, $status);
    }

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->coordinator->status( (int) $request->get_param('id')), 200);
    }

    public function disconnect(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = $this->coordinator->disconnect( (int) $request->get_param('id'));

        return $this->response($result, ($result['ok'] ?? false) === true ? 200 : 422);
    }

    public function canManageMutation(WP_REST_Request $request): bool
    {
        if ( ! Capabilities::canManage()) {
            return false;
        }

        // WordPress REST cookie authentication enforces X-WP-Nonce before
        // dispatch. When a request object exposes headers, keep this route
        // explicit as well; lightweight unit fakes retain capability coverage.
        if (method_exists($request, 'get_header') && function_exists('wp_verify_nonce')) {
            $nonce = (string) $request->get_header('x-wp-nonce');
            return $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') !== false;
        }

        return true;
    }

    public function canManageCallback(WP_REST_Request $request): bool
    {
        unset($request);

        return Capabilities::canManage();
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, int $status): WP_REST_Response|WP_Error
    {
        if (($data['ok'] ?? true) === false) {
            return new WP_Error(
                'provider_oauth_' . sanitize_key( (string) ($data['code'] ?? 'unavailable')),
                __('The provider connection could not be completed.', 'onesmtp'),
                [
					'status' => $status,
					'result' => $data,
				]
            );
        }

        return new WP_REST_Response($data, $status);
    }
}
