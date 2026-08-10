<?php

declare(strict_types=1);

namespace OneSMTP\Suppression;

final class SuppressionSettings
{
    public function __construct(private bool $enabled = false)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
