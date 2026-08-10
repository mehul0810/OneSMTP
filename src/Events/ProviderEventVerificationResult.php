<?php

declare(strict_types=1);

namespace OneSMTP\Events;

/**
 * Explicit verifier outcomes keep unavailable verification fail-closed.
 */
enum ProviderEventVerificationResult: string
{
    case VERIFIED = 'verified';
    case REJECTED = 'rejected';
    case UNAVAILABLE = 'unavailable';

    public function isAccepted(): bool
    {
        return $this === self::VERIFIED;
    }
}
