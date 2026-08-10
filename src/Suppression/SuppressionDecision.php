<?php

declare(strict_types=1);

namespace OneSMTP\Suppression;

/**
 * A future suppression policy must make unavailable state explicit.
 */
enum SuppressionDecision: string
{
    case ALLOW = 'allow';
    case SUPPRESS = 'suppress';
    case UNAVAILABLE = 'unavailable';

    public function shouldSuppress(): bool
    {
        return $this === self::SUPPRESS;
    }
}
