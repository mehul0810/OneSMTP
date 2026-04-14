<?php

declare(strict_types=1);

namespace OneSMTP\Security;

use RuntimeException;

final class SecretVault
{
    private const PREFIX = 'onesmtp:v1:gcm:';

    public function encrypt(string $plainText): string
    {
        $plainText = trim($plainText);
        if ($plainText == '') {
            return $plainText;
        }

        if ($this->isEncrypted($plainText)) {
            return $plainText;
        }

        $iv   = random_bytes(12);
        $tag  = '';
        $key  = $this->deriveKey();
        $data = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($data === false || $tag === '') {
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

        $plainText = openssl_decrypt($cipherText, 'aes-256-gcm', $this->deriveKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plainText === false) {
            throw new RuntimeException('Unable to decrypt secret value.');
        }

        return $plainText;
    }

    public function isEncrypted(string $value): bool
    {
        return strpos($value, self::PREFIX) === 0;
    }

    private function deriveKey(): string
    {
        $authKey       = defined('AUTH_KEY') ? (string) AUTH_KEY : '';
        $secureAuthKey = defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : '';
        $siteUrl       = function_exists('site_url') ? (string) site_url() : 'onesmtp-local';
        $material      = $authKey . '|' . $secureAuthKey . '|' . $siteUrl;

        return hash_hkdf('sha256', $material, 32, 'onesmtp-secret-v1');
    }
}
