<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

/** WordPress transport boundary for OAuth token and revoke requests. */
final class WordPressProviderOAuthHttpClient implements ProviderOAuthHttpClientInterface
{
    public const MAX_RESPONSE_BYTES = 65536;

    public function post(string $url, array $headers, array $body, int $timeout = 15): ProviderOAuthHttpResponse
    {
        if ( ! str_starts_with(strtolower($url), 'https://')) {
            return ProviderOAuthHttpResponse::networkError();
        }

        $response = wp_remote_post(
            $url,
            [
                'headers' => array_merge([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ], $headers),
                'body' => http_build_query($body, '', '&', PHP_QUERY_RFC3986),
                'timeout' => max(5, min(30, $timeout)),
                'redirection' => 0,
            ]
        );

        if (is_wp_error($response)) {
            return ProviderOAuthHttpResponse::networkError();
        }

        $raw = (string) wp_remote_retrieve_body($response);
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            return new ProviderOAuthHttpResponse( (int) wp_remote_retrieve_response_code($response), []);
        }

        $decoded = json_decode($raw, true);

        return new ProviderOAuthHttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            is_array($decoded) ? $decoded : []
        );
    }
}
