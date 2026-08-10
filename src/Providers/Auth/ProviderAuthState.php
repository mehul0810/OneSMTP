<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

/**
 * Stable, redacted lifecycle states for a provider authentication contract.
 *
 * This enum describes capability state only. It intentionally contains no
 * provider credentials, error text, account identifiers, or token material.
 */
enum ProviderAuthState: string
{
    case UNSUPPORTED     = 'unsupported';
    case STATIC          = 'static';
    case DISCONNECTED    = 'disconnected';
    case CONNECTED       = 'connected';
    case REFRESH_FAILED  = 'refresh_failed';
    case REAUTH_REQUIRED = 'reauth_required';
    case REVOKED         = 'revoked';
    case UNKNOWN         = 'unknown';
}
