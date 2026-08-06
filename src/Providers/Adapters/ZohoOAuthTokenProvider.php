<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;
use OneSMTP\Security\SecretVault;

final class ZohoOAuthTokenProvider
{
    public function __construct(private ?SecretVault $vault = null)
    {
        $this->vault = $vault ?? new SecretVault();
    }

    /** @return string|SendResult */
    public function accessToken(ProviderConfig $config): string|SendResult
    {
        $clientId = trim( (string) $config->get('client_id', ''));
        $clientSecret = trim( (string) $config->get('client_secret', ''));
        $refreshToken = trim( (string) $config->get('refresh_token', ''));
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            $accessToken = trim( (string) $config->get('access_token', ''));

            return $accessToken !== ''
                ? $accessToken
                : new SendResult(false, 'missing_credentials', 'Zoho OAuth client ID, client secret, and refresh token are required.');
        }

        $cacheKey = 'onesmtp_zoho_oauth_' . hash('sha256', $clientId . '|' . $refreshToken);
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try {
                return $this->vault->decrypt($cached);
            } catch (\RuntimeException $e) {
                unset($e);
                delete_transient($cacheKey);
            }
        }

        $response = wp_remote_post(
            'https://' . $this->accountsHost(strtolower( (string) $config->get('region', 'com'))) . '/oauth/v2/token',
            [
                'timeout' => max(5, (int) $config->get('timeout', 30)),
                'body' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return new SendResult(false, 'zoho_oauth_network_error', sanitize_text_field($response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode( (string) wp_remote_retrieve_body($response), true);
        $token = is_array($decoded) ? trim( (string) ($decoded['access_token'] ?? '')) : '';
        if ($status < 200 || $status >= 300 || $token === '') {
            $error = is_array($decoded) ? sanitize_text_field( (string) ($decoded['error'] ?? 'token_refresh_failed')) : 'token_refresh_failed';

            return new SendResult(false, 'zoho_oauth_error', 'Zoho OAuth refresh failed: ' . $error);
        }

        $expiresIn = is_array($decoded) ? max(60, (int) ($decoded['expires_in'] ?? 3600)) : 3600;
        set_transient($cacheKey, $this->vault->encrypt($token), max(60, $expiresIn - 300));

        return $token;
    }

    private function accountsHost(string $region): string
    {
        return match ($region) {
            'in' => 'accounts.zoho.in',
            'eu' => 'accounts.zoho.eu',
            'com.au' => 'accounts.zoho.com.au',
            'jp' => 'accounts.zoho.jp',
            'ca' => 'accounts.zohocloud.ca',
            'com.cn' => 'accounts.zoho.com.cn',
            default => 'accounts.zoho.com',
        };
    }
}
