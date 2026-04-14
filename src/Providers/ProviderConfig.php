<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

final class ProviderConfig
{
    private array $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }
}
