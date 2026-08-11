<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

use OneSMTP\Product\FeatureGate;
use OneSMTP\Providers\FailureCategory;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Providers\SendResult;
use OneSMTP\Security\SecretVault;

/**
 * Refreshes short-lived provider access tokens without exposing token values
 * outside the adapter boundary. Cache entries are encrypted and bounded.
 */
final class ProviderOAuthTokenService
{
    private const CACHE_PREFIX = 'onesmtp_oauth_access_';
    private const MAX_TOKEN_LENGTH = 4096;

    private ProviderOAuthHttpClientInterface $http;
    private SecretVault $vault;
    private FeatureGate $featureGate;

    /** @var callable():int */
    private $clock;

    public function __construct(
        ?ProviderOAuthHttpClientInterface $http = null,
        ?SecretVault $vault = null,
        ?callable $clock = null,
        ?FeatureGate $featureGate = null
    ) {
        $this->http = $http ?? new WordPressProviderOAuthHttpClient();
        $this->vault = $vault ?? new SecretVault();
        $this->clock = $clock ?? static fn (): int => time();
        $this->featureGate = $featureGate ?? FeatureGate::fromWordPress();
    }

    /** @return string|SendResult */
    public function accessToken(string $providerType, ProviderConfig $config): string|SendResult
    {
        $providerType = strtolower(trim($providerType));
        $descriptor = ProviderOAuthDescriptor::forProvider(
            $providerType,
            (string) $config->get('region', 'com')
        );
        if ( ! $descriptor instanceof ProviderOAuthDescriptor) {
            return new SendResult(false, 'oauth_unsupported', 'OAuth is not available for this provider.');
        }

        $clientId = trim( (string) $config->get('client_id', ''));
        $clientSecret = trim( (string) $config->get('client_secret', ''));
        $refreshToken = trim( (string) $config->get('refresh_token', ''));
        $accessToken = trim( (string) $config->get('access_token', ''));
        $expiresAt = (int) $config->get('access_token_expires_at', 0);
        $now = (int) ($this->clock)();

        $oauthShaped = $providerType === ProviderTypes::GMAIL
            ? $clientId !== '' || $clientSecret !== '' || $refreshToken !== '' || $accessToken !== '' || $config->get('oauth_scope', '') !== '' || $expiresAt > 0
            : $clientId !== '' || $clientSecret !== '' || $config->get('oauth_scope', '') !== '';
        if ($oauthShaped && ! $this->featureGate->isEnabled(FeatureGate::PROVIDER_AUTH_LIFECYCLE)) {
            return new SendResult(false, 'feature_unavailable', 'OAuth delivery is unavailable on this site.');
        }

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            // Preserve the existing Zoho manual access-token contract. OAuth
            // connections always carry a refresh token and bounded expiry.
            return $accessToken !== '' && strlen($accessToken) <= self::MAX_TOKEN_LENGTH
                ? $accessToken
                : new SendResult(false, 'missing_credentials', 'OAuth credentials are required.');
        }

        $cacheKey = $this->cacheKey($providerType, $clientId, $refreshToken);
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try {
                $cached = $this->vault->decrypt($cached);
                if (strlen($cached) <= self::MAX_TOKEN_LENGTH) {
                    return $cached;
                }
            } catch (\RuntimeException $exception) {
                unset($exception);
            }
            delete_transient($cacheKey);
        }

        if ($accessToken !== '' && $expiresAt > $now + 300 && strlen($accessToken) <= self::MAX_TOKEN_LENGTH) {
            return $accessToken;
        }

        $response = $this->http->post(
            $descriptor->getTokenEndpoint(),
            [],
            [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]
        );
        if ($response->isNetworkError()) {
            return new SendResult(false, 'oauth_network_error', 'OAuth provider is temporarily unavailable.', null, FailureCategory::TIMEOUT);
        }

        $body = $response->getBody();
        $token = is_scalar($body['access_token'] ?? null) ? trim( (string) $body['access_token']) : '';
        if ( ! $response->isSuccessful() || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            $error = strtolower( (string) ($body['error'] ?? ''));
            if ($error === 'invalid_grant' || $response->getStatus() === 401) {
                return new SendResult(false, 'oauth_invalid_grant', 'OAuth credentials require reconnection.', null, FailureCategory::AUTHENTICATION);
            }

            return new SendResult(false, 'oauth_refresh_failed', 'OAuth provider rejected the refresh request.');
        }

        $grantedScope = trim( (string) ($body['scope'] ?? ''));
        if ($grantedScope !== '' && ! $this->hasScope($grantedScope, $descriptor->getScope())) {
            return new SendResult(false, 'oauth_scope_missing', 'The provider did not grant the required send scope.', null, FailureCategory::AUTHENTICATION);
        }

        $expiresIn = max(60, min(86400, (int) ($body['expires_in'] ?? 3600)));
        try {
            set_transient($cacheKey, $this->vault->encrypt($token), max(60, $expiresIn - 300));
        } catch (\RuntimeException $exception) {
            unset($exception);
            return new SendResult(false, 'oauth_secret_unavailable', 'OAuth credentials cannot be protected on this site.');
        }

        return $token;
    }

    private function cacheKey(string $providerType, string $clientId, string $refreshToken): string
    {
        return self::CACHE_PREFIX . hash('sha256', $providerType . '|' . $clientId . '|' . $refreshToken);
    }

    private function hasScope(string $granted, string $required): bool
    {
        $grantedScopes = preg_split('/\s+/', trim($granted)) ?: [];

        return in_array($required, $grantedScopes, true);
    }
}
