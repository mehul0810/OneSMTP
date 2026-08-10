<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

use OneSMTP\Product\FeatureGate;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\ProviderRepository;

/**
 * Coordinates the site-local OAuth lifecycle. All externally visible results
 * are bounded codes/statuses; provider responses and credential material never
 * cross this boundary.
 */
final class ProviderOAuthLifecycleCoordinator
{
    private const STATE_TTL = 120;
    private const VERIFIED_TTL = 600;

    private ProviderOAuthHttpClientInterface $http;
    private ProviderOAuthStateStoreInterface $states;

    /** @var callable():int */
    private $clock;

    public function __construct(
        private ProviderRepository $providers,
        private FeatureGate $featureGate,
        ?ProviderOAuthHttpClientInterface $http = null,
        ?ProviderOAuthStateStoreInterface $states = null,
        ?callable $clock = null
    ) {
        $this->http = $http ?? new WordPressProviderOAuthHttpClient();
        $this->states = $states ?? new WordPressProviderOAuthStateStore();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array<string,mixed> */
    public function begin(int $providerId, string $returnTarget = ''): array
    {
        if ( ! $this->featureGate->isEnabled(FeatureGate::PROVIDER_AUTH_LIFECYCLE)) {
            return $this->failure('feature_unavailable');
        }

        $provider = $this->providers->find($providerId);
        if ( ! is_array($provider)) {
            return $this->failure('unavailable');
        }

        $providerType = sanitize_key( (string) ($provider['adapter_type'] ?? ''));
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $descriptor = ProviderOAuthDescriptor::forProvider($providerType, (string) ($config['region'] ?? 'com'));
        $clientId = trim( (string) ($config['client_id'] ?? ''));
        $clientSecret = trim( (string) ($config['client_secret'] ?? ''));
        if ( ! $descriptor instanceof ProviderOAuthDescriptor || $clientId === '' || $clientSecret === '') {
            return $this->failure('configuration_required');
        }

        $callbackUrl = $this->callbackUrl($providerId);
        if ($callbackUrl === '') {
            return $this->failure('https_callback_required');
        }

        try {
            $state = $this->randomUrlToken(32);
            $verifier = $descriptor->usesPkce() ? $this->randomUrlToken(48) : '';
        } catch (\Throwable $exception) {
            unset($exception);
            return $this->failure('state_unavailable');
        }

        $record = [
            'provider_id' => $providerId,
            'provider_type' => $providerType,
            'user_id' => function_exists('get_current_user_id') ? max(1, (int) get_current_user_id()) : 0,
            'return_target' => $this->safeReturnTarget($returnTarget),
            'callback_url' => $callbackUrl,
            'code_verifier' => $verifier,
            'created_at' => (int) ($this->clock)(),
        ];
        if ( ! $this->states->put(hash('sha256', $state), $record, self::STATE_TTL)) {
            return $this->failure('state_unavailable');
        }

        $query = [
            'client_id' => $clientId,
            'redirect_uri' => $callbackUrl,
            'response_type' => 'code',
            'scope' => $descriptor->getScope(),
            'access_type' => 'offline',
            'state' => $state,
        ];
        if ($providerType === ProviderTypes::GMAIL) {
            $query['include_granted_scopes'] = 'false';
            $query['prompt'] = 'consent';
        } else {
            $query['prompt'] = 'consent';
            $query['code_challenge'] = $this->pkceChallenge($verifier);
            $query['code_challenge_method'] = 'S256';
        }

        return [
            'ok' => true,
            'code' => 'authorization_started',
            'provider_id' => $providerId,
            'provider_type' => $providerType,
            'authorization_url' => $descriptor->getAuthorizationEndpoint() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            'callback_url' => $callbackUrl,
            'expires_in' => self::STATE_TTL,
        ];
    }

    /** @param array<string,mixed> $params */
    public function callback(int $providerId, array $params): array
    {
        if ( ! $this->featureGate->isEnabled(FeatureGate::PROVIDER_AUTH_LIFECYCLE)) {
            return $this->failure('feature_unavailable');
        }

        $state = trim( (string) ($params['state'] ?? ''));
        if ($state === '' || strlen($state) > 256) {
            return $this->failure('invalid_state');
        }

        $record = $this->states->consume(hash('sha256', $state));
        if ( ! is_array($record)
            || (int) ($record['provider_id'] ?? 0) !== $providerId
            || (int) ($record['user_id'] ?? 0) !== (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0)
        ) {
            return $this->failure('invalid_state');
        }

        $provider = $this->providers->find($providerId);
        if ( ! is_array($provider)) {
            return $this->failure('invalid_state', (string) ($record['return_target'] ?? ''));
        }

        $providerType = sanitize_key( (string) ($provider['adapter_type'] ?? ''));
        if ($providerType !== sanitize_key( (string) ($record['provider_type'] ?? ''))) {
            return $this->failure('invalid_state', (string) ($record['return_target'] ?? ''));
        }

        $returnTarget = $this->safeReturnTarget( (string) ($record['return_target'] ?? ''));
        if (isset($params['error'])) {
            return $this->failure('authorization_denied', $returnTarget);
        }

        $code = trim( (string) ($params['code'] ?? ''));
        if ($code === '' || strlen($code) > 4096) {
            return $this->failure('invalid_code', $returnTarget);
        }

        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $descriptor = ProviderOAuthDescriptor::forProvider($providerType, (string) ($config['region'] ?? 'com'));
        $callbackUrl = (string) ($record['callback_url'] ?? '');
        if ( ! $descriptor instanceof ProviderOAuthDescriptor || ! $this->isHttpsUrl($callbackUrl)) {
            return $this->failure('https_callback_required', $returnTarget);
        }

        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $callbackUrl,
            'client_id' => trim( (string) ($config['client_id'] ?? '')),
            'client_secret' => trim( (string) ($config['client_secret'] ?? '')),
        ];
        if ($descriptor->usesPkce()) {
            $body['code_verifier'] = (string) ($record['code_verifier'] ?? '');
        }

        $response = $this->http->post($descriptor->getTokenEndpoint(), [], $body);
        if ($response->isNetworkError()) {
            return $this->failure('network_error', $returnTarget);
        }

        $responseBody = $response->getBody();
        $accessToken = is_scalar($responseBody['access_token'] ?? null) ? trim( (string) $responseBody['access_token']) : '';
        $refreshToken = is_scalar($responseBody['refresh_token'] ?? null) ? trim( (string) $responseBody['refresh_token']) : '';
        $scope = trim( (string) ($responseBody['scope'] ?? ''));
        if ( ! $response->isSuccessful() || $accessToken === '' || strlen($accessToken) > 4096) {
            return $this->failure($this->isInvalidGrant($responseBody, $response->getStatus()) ? 'reauth_required' : 'token_exchange_failed', $returnTarget);
        }

        if ( ! $this->hasScope($scope, $descriptor->getScope())) {
            return $this->failure('required_scope_missing', $returnTarget);
        }

        if ($refreshToken === '') {
            $refreshToken = trim( (string) ($config['refresh_token'] ?? ''));
        }
        if ($refreshToken === '' || strlen($refreshToken) > 4096) {
            return $this->failure('refresh_token_required', $returnTarget);
        }

        $expiresIn = max(60, min(86400, (int) ($responseBody['expires_in'] ?? 3600)));
        $provider['config'] = array_merge($config, [
            'access_token' => $accessToken,
            'access_token_expires_at' => (int) ($this->clock)() + $expiresIn,
            'refresh_token' => $refreshToken,
            'oauth_scope' => $descriptor->getScope(),
        ]);
        if ($this->providers->save($provider) <= 0) {
            return $this->failure('local_save_failed', $returnTarget);
        }

        set_transient($this->verifiedKey($providerId), [
            'provider_type' => $providerType,
            'verified_at' => (int) ($this->clock)(),
        ], self::VERIFIED_TTL);

        return [
            'ok' => true,
            'code' => 'connected',
            'provider_id' => $providerId,
            'provider_type' => $providerType,
            'return_target' => $returnTarget,
            'status' => $this->status($providerId),
        ];
    }

    /** @return array<string,mixed> */
    public function status(int $providerId): array
    {
        if ( ! $this->featureGate->isEnabled(FeatureGate::PROVIDER_AUTH_LIFECYCLE)) {
            return [
				'available' => false,
				'code' => 'feature_unavailable',
				'status' => ProviderAuthStatus::forState(ProviderAuthState::UNSUPPORTED)->toArray(),
			];
        }

        $provider = $this->providers->find($providerId);
        if ( ! is_array($provider)) {
            return [
				'available' => false,
				'code' => 'unavailable',
				'status' => ProviderAuthStatus::forState(ProviderAuthState::UNKNOWN)->toArray(),
			];
        }

        $providerType = sanitize_key( (string) ($provider['adapter_type'] ?? ''));
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $descriptor = ProviderOAuthDescriptor::forProvider($providerType, (string) ($config['region'] ?? 'com'));
        if ( ! $descriptor instanceof ProviderOAuthDescriptor) {
            return [
				'available' => false,
				'code' => 'unsupported',
				'status' => ProviderAuthStatus::forState(ProviderAuthState::UNSUPPORTED)->toArray(),
			];
        }

        $verified = get_transient($this->verifiedKey($providerId));
        $refresh = is_array($verified) && (string) ($verified['provider_type'] ?? '') === $providerType
            ? ProviderAuthRefreshResult::success()
            : null;
        $evidence = $refresh !== null && trim( (string) ($config['refresh_token'] ?? '')) !== ''
            ? ProviderAuthRevocationEvidence::verifiedTokenBearing()
            : ProviderAuthRevocationEvidence::unavailable();
        $status = (new ProviderAuthEvaluator())->evaluate(
            ProviderAuthContext::fromProviderConfig($providerType, new ProviderConfig($config), $refresh, [ 'client_id', 'client_secret', 'refresh_token' ], $evidence)
        );

        return [
			'available' => true,
			'code' => 'status',
			'provider_id' => $providerId,
			'provider_type' => $providerType,
			'status' => $status->toArray(),
		];
    }

    /** @return array<string,mixed> */
    public function disconnect(int $providerId): array
    {
        if ( ! $this->featureGate->isEnabled(FeatureGate::PROVIDER_AUTH_LIFECYCLE)) {
            return $this->failure('feature_unavailable');
        }

        $provider = $this->providers->find($providerId);
        if ( ! is_array($provider)) {
            return $this->failure('unavailable');
        }

        $providerType = sanitize_key( (string) ($provider['adapter_type'] ?? ''));
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $descriptor = ProviderOAuthDescriptor::forProvider($providerType, (string) ($config['region'] ?? 'com'));
        $token = $providerType === ProviderTypes::ZOHO_MAIL
            ? trim( (string) ($config['refresh_token'] ?? ''))
            : trim( (string) ($config['access_token'] ?? ''));
        if ($providerType === ProviderTypes::GMAIL && $token === '') {
            $token = trim( (string) ($config['refresh_token'] ?? ''));
        }
        $remoteOk = $token === '';
        if ($descriptor instanceof ProviderOAuthDescriptor && $token !== '') {
            $response = $this->http->post($descriptor->getRevokeEndpoint(), [], [ 'token' => $token ]);
            $remoteOk = $response->isSuccessful() || ($response->getStatus() === 400 && $providerType === ProviderTypes::GMAIL);
        }

        $localOk = $this->providers->clearOAuthCredentials($providerId);
        delete_transient($this->verifiedKey($providerId));

        return [
            'ok' => $localOk,
            'code' => $localOk && $remoteOk ? 'disconnected' : ($localOk ? 'disconnected_remote_retry' : 'disconnect_failed'),
            'provider_id' => $providerId,
            'provider_type' => $providerType,
        ];
    }

    public function callbackUrl(int $providerId): string
    {
        $url = rest_url('onesmtp/v1/providers/' . $providerId . '/oauth/callback');

        return $this->isHttpsUrl($url) ? $url : '';
    }

    /** @return array<string,mixed> */
    private function failure(string $code, string $returnTarget = ''): array
    {
        $result = [
			'ok' => false,
			'code' => sanitize_key($code),
		];
        if ($returnTarget !== '') {
            $result['return_target'] = $this->safeReturnTarget($returnTarget);
        }

        return $result;
    }

    private function randomUrlToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function verifiedKey(int $providerId): string
    {
        return 'onesmtp_oauth_verified_' . max(1, $providerId);
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = wp_parse_url($url);

        return is_array($parts) && strtolower( (string) ($parts['scheme'] ?? '')) === 'https' && (string) ($parts['host'] ?? '') !== '';
    }

    private function safeReturnTarget(string $target): string
    {
        $fallback = admin_url('options-general.php?page=onesmtp&tab=onesmtp-providers');
        $target = trim($target);
        if ($target === '') {
            return $fallback;
        }

        $parts = wp_parse_url($target);
        $fallbackParts = wp_parse_url($fallback);
        if ( ! is_array($parts) || ! is_array($fallbackParts)
            || strtolower( (string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower( (string) ($parts['host'] ?? '')) !== strtolower( (string) ($fallbackParts['host'] ?? ''))
            || isset($parts['user'], $parts['pass'])
        ) {
            return $fallback;
        }

        return $target;
    }

    private function hasScope(string $granted, string $required): bool
    {
        $scopes = preg_split('/\s+/', trim($granted)) ?: [];

        return in_array($required, $scopes, true);
    }

    /** @param array<string,mixed> $body */
    private function isInvalidGrant(array $body, int $status): bool
    {
        return $status === 401 || strtolower( (string) ($body['error'] ?? '')) === 'invalid_grant';
    }
}
