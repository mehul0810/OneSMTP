<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

enum UpdateState: string
{
    case UNAVAILABLE = 'unavailable';
    case CURRENT = 'current';
    case UPDATE_AVAILABLE = 'update_available';
    case ERROR = 'error';
}
