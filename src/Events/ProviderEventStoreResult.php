<?php

declare(strict_types=1);

namespace OneSMTP\Events;

/**
 * Persistence outcomes used by the webhook boundary.
 */
enum ProviderEventStoreResult: string
{
    case INSERTED = 'inserted';
    case DUPLICATE = 'duplicate';
    case FAILED = 'failed';

    public function isAccepted(): bool
    {
        return $this !== self::FAILED;
    }
}
