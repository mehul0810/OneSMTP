<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

interface UpdateProvider
{
    public function status(): UpdateStatus;
}
