<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

interface EntitlementProvider
{
    public function hasProEntitlement(): bool;
}
