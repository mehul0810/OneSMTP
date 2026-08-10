<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

use OneSMTP\Providers\ProviderConfig;

/**
 * Typed, non-sensitive input for the pure provider-auth evaluator.
 */
final class ProviderAuthContext
{
    public function __construct(
        private string $providerType,
        private ProviderAuthConfiguration $configuration,
        private ?ProviderAuthRefreshResult $refreshResult = null
    ) {
        $this->providerType = strtolower(trim($providerType));
    }

    /**
     * @param array<int,string> $refreshCredentialKeys
     */
    public static function fromProviderConfig(
        string $providerType,
        ProviderConfig $config,
        ?ProviderAuthRefreshResult $refreshResult = null,
        array $refreshCredentialKeys = [ 'client_id', 'client_secret', 'refresh_token' ]
    ): self {
        return new self(
            $providerType,
            ProviderAuthConfiguration::fromProviderConfig($config, $refreshCredentialKeys),
            $refreshResult
        );
    }

    public function getProviderType(): string
    {
        return $this->providerType;
    }

    public function getConfiguration(): ProviderAuthConfiguration
    {
        return $this->configuration;
    }

    public function getRefreshResult(): ?ProviderAuthRefreshResult
    {
        return $this->refreshResult;
    }
}
