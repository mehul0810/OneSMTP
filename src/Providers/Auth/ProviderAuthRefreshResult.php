<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

use OneSMTP\Providers\FailureCategory;
use OneSMTP\Providers\SendResult;

/**
 * Redacted result of a provider refresh attempt.
 */
final class ProviderAuthRefreshResult
{
    private function __construct(private ProviderAuthRefreshState $state)
    {
    }

    public static function success(): self
    {
        return new self(ProviderAuthRefreshState::SUCCESS);
    }

    public static function networkError(): self
    {
        return new self(ProviderAuthRefreshState::NETWORK_ERROR);
    }

    public static function invalidCredentials(): self
    {
        return new self(ProviderAuthRefreshState::INVALID_CREDENTIALS);
    }

    public static function revoked(): self
    {
        return new self(ProviderAuthRefreshState::REVOKED);
    }

    public static function unknown(): self
    {
        return new self(ProviderAuthRefreshState::UNKNOWN);
    }

    /**
     * Map the existing provider result contract without retaining its raw
     * message, token, client, or account values.
     */
    public static function fromSendResult(SendResult $result): self
    {
        if ($result->isSuccess()) {
            return self::success();
        }

        $code = strtolower($result->getCode());
        $message = strtolower($result->getMessage());
        $combined = $code . ' ' . $message;

        if (str_contains($combined, 'revoked') || str_contains($combined, 'access_denied')) {
            return self::revoked();
        }

        if (
            str_contains($code, 'network')
            || str_contains($code, 'timeout')
            || $result->getFailureCategory() === FailureCategory::TIMEOUT
            || str_contains($combined, 'network')
            || str_contains($combined, 'timeout')
        ) {
            return self::networkError();
        }

        if (
            str_contains($combined, 'invalid_grant')
            || str_contains($combined, 'invalid credential')
            || str_contains($combined, 'invalid_token')
            || $result->getFailureCategory() === FailureCategory::AUTHENTICATION
        ) {
            return self::invalidCredentials();
        }

        return self::unknown();
    }

    public function getState(): ProviderAuthRefreshState
    {
        return $this->state;
    }
}
