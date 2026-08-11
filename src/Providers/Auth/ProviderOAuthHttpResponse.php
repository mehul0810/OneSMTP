<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

/** Bounded, redacted response from a provider OAuth HTTP call. */
final class ProviderOAuthHttpResponse
{
    /** @param array<string,mixed> $body */
    public function __construct(
        private int $status,
        private array $body,
        private bool $networkError = false
    ) {
    }

    public static function networkError(): self
    {
        return new self(0, [], true);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function getBody(): array
    {
        return $this->body;
    }

    public function isNetworkError(): bool
    {
        return $this->networkError;
    }

    public function isSuccessful(): bool
    {
        return ! $this->networkError && $this->status >= 200 && $this->status < 300;
    }
}
