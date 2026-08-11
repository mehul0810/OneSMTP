<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Support;

use OneSMTP\Product\Licensing\EntitlementProvider;

final class FakeEntitlementProvider implements EntitlementProvider
{
    public function __construct(private bool $entitled)
    {
    }

    public function hasProEntitlement(): bool
    {
        return $this->entitled;
    }
}
