<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

enum LicenseState: string
{
    case UNAVAILABLE = 'unavailable';
    case INACTIVE = 'inactive';
    case ACTIVE = 'active';
    case INVALID = 'invalid';
    case EXPIRED = 'expired';
    case ERROR = 'error';
}
