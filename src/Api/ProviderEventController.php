<?php

declare(strict_types=1);

namespace OneSMTP\Api;

use OneSMTP\Events\ProviderEventIngestionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ProviderEventController
{
    public function __construct(private ?ProviderEventIngestionService $ingestion)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            'onesmtp/v1',
            '/webhooks/mailgun',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'receive'],
                    'permission_callback' => [self::class, 'allowPublic'],
                ],
            ]
        );
    }

    public function receive(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = method_exists($request, 'get_body') ? (string) $request->get_body() : '';
        $contentType = method_exists($request, 'get_header') ? (string) $request->get_header('content-type') : '';
        $headers = method_exists($request, 'get_headers') ? $this->normalizeHeaders($request->get_headers()) : [];

        if ($this->ingestion instanceof ProviderEventIngestionService && $this->ingestion->ingest($body, $contentType, $headers)) {
            return new WP_REST_Response(['accepted' => true], 202);
        }

        return new WP_Error(
            'provider_event_rejected',
            __('Request could not be accepted.', 'onesmtp'),
            ['status' => 400]
        );
    }

    public static function allowPublic(): bool
    {
        return true;
    }

    /**
     * @param array<string,mixed> $headers
     * @return array<string,string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            if (is_array($value)) {
                $value = reset($value);
            }
            if ( ! is_scalar($value) ) {
                continue;
            }
            $normalized[ strtolower( (string) $key ) ] = trim( (string) $value );
        }

        return $normalized;
    }
}
