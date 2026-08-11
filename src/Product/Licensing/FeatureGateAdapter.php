<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

use OneSMTP\Product\FeatureGate;
use Throwable;

final class FeatureGateAdapter
{
    public function __construct(private EntitlementProvider $entitlements)
    {
    }

    /** @param array<string,bool> $flags */
    public function create(array $flags = []): FeatureGate
    {
        try {
            $entitled = $this->entitlements->hasProEntitlement();
        } catch (Throwable) {
            $entitled = false;
        }

        return new FeatureGate($flags, $entitled === true);
    }
}
