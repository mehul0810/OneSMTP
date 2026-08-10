<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

use OneSMTP\Providers\ProviderConfig;

/**
 * Non-sensitive evidence extracted from an existing provider configuration.
 *
 * Only presence flags cross this boundary; credential values are never stored
 * in this value object or returned from its methods.
 */
final class ProviderAuthConfiguration
{
    /**
     * @param array<int,string> $refreshCredentialKeys
     */
    public static function fromProviderConfig(
        ProviderConfig $config,
        array $refreshCredentialKeys = [ 'client_id', 'client_secret', 'refresh_token' ]
    ): self {
        $present = 0;
        foreach ($refreshCredentialKeys as $key) {
            $value = $config->get($key, null);
            if (is_string($value) && trim($value) !== '') {
                ++$present;
            }
        }

        $required = count($refreshCredentialKeys);

        return new self($required > 0 && $present === $required, $present > 0 && $present < $required);
    }

    private function __construct(
        private bool $hasRefreshCredentials,
        private bool $hasPartialRefreshCredentials
    ) {
    }

    public function hasRefreshCredentials(): bool
    {
        return $this->hasRefreshCredentials;
    }

    public function hasPartialRefreshCredentials(): bool
    {
        return $this->hasPartialRefreshCredentials;
    }
}
