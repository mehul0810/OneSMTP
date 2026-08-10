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
        add_filter('rest_pre_dispatch', [$this, 'preDispatch'], 10, 3);

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
        $contentLength = method_exists($request, 'get_header') ? $request->get_header('content-length') : '';
        if ( ! $this->hasBoundedContentLength( (string) $contentLength ) ) {
            return $this->rejectedResponse();
        }

        $body = method_exists($request, 'get_body') ? (string) $request->get_body() : '';
        $contentType = method_exists($request, 'get_header') ? (string) $request->get_header('content-type') : '';
        $headers = method_exists($request, 'get_headers') ? $this->normalizeHeaders($request->get_headers()) : [];

        if ($this->ingestion instanceof ProviderEventIngestionService && $this->ingestion->ingest($body, $contentType, $headers)) {
            return new WP_REST_Response(['accepted' => true], 202);
        }

        return $this->rejectedResponse();
    }

    /**
     * WordPress parses application/json before dispatch validation. Hijack this
     * route at the pre-dispatch boundary so malformed provider JSON receives
     * the same generic response as every other rejected webhook.
     */
    public function preDispatch(mixed $result, mixed $server, WP_REST_Request $request): mixed
    {
        unset($server);
        if ($result !== null || ! method_exists($request, 'get_route') || $request->get_route() !== '/onesmtp/v1/webhooks/mailgun') {
            return $result;
        }

        if (method_exists($request, 'get_method') && $request->get_method() !== 'POST') {
            return $result;
        }

        return $this->receive($request);
    }

    private function rejectedResponse(): WP_Error
    {
        return new WP_Error(
            'provider_event_rejected',
            __('Request could not be accepted.', 'onesmtp'),
            ['status' => 400]
        );
    }

    private function hasBoundedContentLength(string $contentLength): bool
    {
        $contentLength = trim($contentLength);
        if ($contentLength === '' || strlen($contentLength) > 5 || ! ctype_digit($contentLength)) {
            return false;
        }

        return (int) $contentLength <= ProviderEventIngestionService::MAX_BODY_BYTES;
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
