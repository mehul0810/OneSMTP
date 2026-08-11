<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Support;

use OneSMTP\Product\Licensing\UpdateProvider;
use OneSMTP\Product\Licensing\UpdateStatus;

final class FakeUpdateProvider implements UpdateProvider
{
    public function __construct(private UpdateStatus $result)
    {
    }

    public function status(): UpdateStatus
    {
        return $this->result;
    }
}
