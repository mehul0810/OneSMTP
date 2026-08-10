<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Security;

use OneSMTP\Security\SecretVault;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecretVaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['onesmtp_test_wp_salt']);
    }

    public function test_wp_salt_is_preferred_for_encryption(): void
    {
        $salt = str_repeat('wp-managed-salt-', 4);
        $constants = [
            'AUTH_KEY' => str_repeat('constant-auth-key-', 4),
            'SECURE_AUTH_KEY' => str_repeat('constant-secure-key-', 4),
        ];
        $vault = new SecretVault(static fn (): string => $salt, $constants);
        $ciphertext = $vault->encrypt('fixture-secret');

        self::assertNotSame('fixture-secret', $ciphertext);
        self::assertSame('fixture-secret', $vault->decrypt($ciphertext));

        $constantsOnly = new SecretVault(static fn (): string => '', $constants);
        $this->expectException(RuntimeException::class);
        $constantsOnly->decrypt($ciphertext);
    }

    public function test_empty_salt_without_strong_constants_fails_closed(): void
    {
        $vault = new SecretVault(static fn (): string => '', []);

        $this->expectException(RuntimeException::class);
        $vault->encrypt('fixture-secret');
    }

    public function test_strong_constants_remain_a_supported_fallback(): void
    {
        $constants = [
            'AUTH_KEY' => str_repeat('constant-auth-key-', 4),
            'SECURE_AUTH_KEY' => '',
        ];
        $vault = new SecretVault(static fn (): string => '', $constants);
        $ciphertext = $vault->encrypt('fixture-secret');

        self::assertSame('fixture-secret', $vault->decrypt($ciphertext));
    }
}
