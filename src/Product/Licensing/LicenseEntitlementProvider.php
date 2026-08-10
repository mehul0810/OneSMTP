<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

use Throwable;

final class LicenseEntitlementProvider implements EntitlementProvider
{
    public function __construct(private LicenseClient $client)
    {
    }

    public function hasProEntitlement(): bool
    {
        try {
            return $this->client->status()->state() === LicenseState::ACTIVE;
        } catch (Throwable) {
            return false;
        }
    }
}
