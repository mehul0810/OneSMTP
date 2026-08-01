<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

final class AdminScreenRegistry
{
    /** @var array<string,AdminScreenDefinition> */
    private array $screens = [];

    public function register(AdminScreenDefinition $screen): void
    {
        $this->screens[$screen->id()] = $screen;
    }

    /** @return array<int,AdminScreenDefinition> */
    public function all(): array
    {
        return array_values($this->screens);
    }

    public function resolve(string $id): ?AdminScreenDefinition
    {
        if (isset($this->screens[$id])) {
            return $this->screens[$id];
        }

        foreach ($this->screens as $screen) {
            if (in_array($id, $screen->aliases(), true)) {
                return $screen;
            }
        }

        return null;
    }
}
