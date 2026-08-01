<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

final class AdminRequest
{
    public function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    }

    public function getAction(string $key): string
    {
        return $this->value($_GET, $key);
    }

    public function postAction(string $key): string
    {
        return $this->value($_POST, $key);
    }

    /** @param array<string,mixed> $source */
    private function value(array $source, string $key): string
    {
        return isset($source[$key]) ? sanitize_key(wp_unslash((string) $source[$key])) : '';
    }
}
