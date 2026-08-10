<?php

declare(strict_types=1);

namespace OneSMTP\Security;

use RuntimeException;

final class SecretVault
{
    private const PREFIX = 'onesmtp:v1:gcm:';

    /** @var callable():string|null */
    private $saltProvider;

    /** @var array<string,string>|null */
    private ?array $constants;

    /**
     * @param callable():string|null $saltProvider
     * @param array<string,string>|null $constants Optional injectable constants for tests.
     */
    public function __construct(?callable $saltProvider = null, ?array $constants = null)
    {
        $this->saltProvider = $saltProvider ?? static function (): string {
            return function_exists('wp_salt') ? (string) wp_salt('auth') : '';
        };
        $this->constants = $constants;
    }

    public function encrypt(string $plainText): string
    {
        $plainText = trim($plainText);
        if ($plainText === '') {
            return $plainText;
        }

        if ($this->isEncrypted($plainText)) {
            return $plainText;
        }

        $iv   = random_bytes(12);
        $tag  = '';
        $key  = $this->deriveKey();
        $data = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($data === false) {
            throw new RuntimeException('Unable to encrypt secret value.');
        }

        return self::PREFIX . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($data);
    }

    public function decrypt(string $encryptedValue): string
    {
        if (! $this->isEncrypted($encryptedValue)) {
            return $encryptedValue;
        }

        $parts = explode(':', $encryptedValue, 6);
        if (count($parts) !== 6) {
            throw new RuntimeException('Malformed encrypted secret value.');
        }

        $iv         = base64_decode($parts[3], true);
        $tag        = base64_decode($parts[4], true);
        $cipherText = base64_decode($parts[5], true);

        if ($iv === false || $tag === false || $cipherText === false) {
            throw new RuntimeException('Invalid base64 content in encrypted secret value.');
        }

        foreach ($this->keyCandidates() as $key) {
            $plainText = openssl_decrypt($cipherText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plainText !== false) {
                return $plainText;
            }
        }

        throw new RuntimeException('Unable to decrypt secret value.');
    }

    public function isEncrypted(string $value): bool
    {
        return strpos($value, self::PREFIX) === 0;
    }

    private function deriveKey(): string
    {
        $salt = trim((string) ($this->saltProvider)());
        if ($this->isStrongSecret($salt)) {
            return hash_hkdf('sha256', $salt, 32, 'onesmtp-secret-v1');
        }

        $constants = $this->secretConstants();
        $authKey = trim((string) ($constants['AUTH_KEY'] ?? ''));
        $secureAuthKey = trim((string) ($constants['SECURE_AUTH_KEY'] ?? ''));
        if (! $this->isStrongSecret($authKey) && ! $this->isStrongSecret($secureAuthKey)) {
            throw new RuntimeException('A strong WordPress secret is required to encrypt credentials.');
        }

        $siteUrl = function_exists('site_url') ? (string) site_url() : '';
        $material = $authKey . '|' . $secureAuthKey . '|' . $siteUrl;

        return hash_hkdf('sha256', $material, 32, 'onesmtp-secret-v1');
    }

    /**
     * Include the pre-wp_salt constants-derived key for ciphertext created by
     * older releases. Values created without a strong secret are intentionally
     * not recoverable because that boundary used a predictable site URL.
     *
     * @return array<int,string>
     */
    private function keyCandidates(): array
    {
        $keys = [$this->deriveKey()];
        $constants = $this->secretConstants();
        $authKey = trim((string) ($constants['AUTH_KEY'] ?? ''));
        $secureAuthKey = trim((string) ($constants['SECURE_AUTH_KEY'] ?? ''));
        if ($this->isStrongSecret($authKey) || $this->isStrongSecret($secureAuthKey)) {
            $siteUrl = function_exists('site_url') ? (string) site_url() : '';
            $legacyMaterial = $authKey . '|' . $secureAuthKey . '|' . $siteUrl;
            $legacyKey = hash_hkdf('sha256', $legacyMaterial, 32, 'onesmtp-secret-v1');
            if (! in_array($legacyKey, $keys, true)) {
                $keys[] = $legacyKey;
            }
        }

        return $keys;
    }

    /** @return array<string,string> */
    private function secretConstants(): array
    {
        if ($this->constants !== null) {
            return $this->constants;
        }

        return [
            'AUTH_KEY' => defined('AUTH_KEY') ? (string) AUTH_KEY : '',
            'SECURE_AUTH_KEY' => defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : '',
        ];
    }

    private function isStrongSecret(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if (strlen($normalized) < 32 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }

        return ! in_array($normalized, [
            'put your unique phrase here',
            'changeme',
            'change-me',
        ], true);
    }
}
