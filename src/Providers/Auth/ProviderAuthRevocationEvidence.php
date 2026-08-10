<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

/**
 * Explicit future-approved evidence for a token-bearing revoke capability.
 *
 * No token value is retained. The default is unavailable and therefore
 * cannot authorize a revoke affordance.
 */
final class ProviderAuthRevocationEvidence
{
    private function __construct(private bool $verifiedTokenBearing)
    {
    }

    public static function unavailable(): self
    {
        return new self(false);
    }

    public static function verifiedTokenBearing(): self
    {
        return new self(true);
    }

    public function allowsRevocation(): bool
    {
        return $this->verifiedTokenBearing;
    }
}
