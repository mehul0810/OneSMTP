<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

/**
 * Redacted outcomes from an already-performed provider refresh attempt.
 */
enum ProviderAuthRefreshState: string
{
    case SUCCESS              = 'success';
    case NETWORK_ERROR        = 'network_error';
    case INVALID_CREDENTIALS  = 'invalid_credentials';
    case REVOKED              = 'revoked';
    case UNKNOWN              = 'unknown';
}
