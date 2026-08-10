<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

final class UnavailableUpdateProvider implements UpdateProvider
{
    public function status(): UpdateStatus
    {
        return UpdateStatus::unavailable();
    }
}
