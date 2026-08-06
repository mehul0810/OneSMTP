<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

final class AdminRequest
{
    public function method(): string
    {
        return strtoupper( (string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    }

    public function getAction(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The owning admin handler verifies its action-specific nonce before mutation or export.
        return $this->value($_GET, $key);
    }

    public function postAction(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The owning admin handler verifies capability and nonce immediately after routing the action.
        return $this->value($_POST, $key);
    }

    /** @param array<string,mixed> $source */
    private function value(array $source, string $key): string
    {
        return isset($source[ $key ]) ? sanitize_key(wp_unslash( (string) $source[ $key ])) : '';
    }
}
