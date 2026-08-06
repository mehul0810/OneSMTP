<?php

declare(strict_types=1);

namespace OneSMTP\Security;

use OneSMTP\Core\Capabilities;
use RuntimeException;

final class AdminGuard
{
    public function assertCanManage(): void
    {
        $this->assertCapability(Capabilities::MANAGE_PLUGIN, 'You are not allowed to manage Aculect Mail settings.');
    }

    public function assertCanResend(): void
    {
        $this->assertCapability(Capabilities::RESEND_EMAILS, 'You are not allowed to resend Aculect Mail emails.');
    }

    public function verifyNonce(string $action, string $field = '_wpnonce'): void
    {
        if (! isset($_POST[$field])) {
            throw new RuntimeException('Missing security nonce.');
        }

        $nonce = sanitize_text_field(wp_unslash((string) $_POST[$field]));
        if (! wp_verify_nonce($nonce, $action)) {
            throw new RuntimeException('Invalid security nonce.');
        }
    }

    public function assertManageRequest(string $nonceAction, string $nonceField = '_wpnonce'): void
    {
        if (! $this->shouldEnforceForCurrentRequest()) {
            return;
        }

        $this->assertCanManage();
        $this->verifyNonce($nonceAction, $nonceField);
    }

    private function assertCapability(string $capability, string $message): void
    {
        if (! function_exists('current_user_can') || ! current_user_can($capability)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The caller supplies a fixed internal message; presentation layers escape it before output.
            throw new RuntimeException($message);
        }
    }

    private function shouldEnforceForCurrentRequest(): bool
    {
        if (function_exists('is_admin') && ! is_admin()) {
            return false;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';

        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
