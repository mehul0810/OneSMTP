<?php

declare(strict_types=1);

namespace OneSMTP\Security;

use InvalidArgumentException;

/**
 * Produce site-secret HMAC digests for privacy-safe correlation identifiers.
 */
final class SiteSecretHmac
{
    private const MAX_CONTEXT_LENGTH = 64;
    private const MAX_VALUE_LENGTH = 4096;

    public function __construct(private string $siteSecret)
    {
        if (trim($this->siteSecret) === '' || self::hasControlCharacters($this->siteSecret)) {
            throw new InvalidArgumentException('A non-empty site secret is required.');
        }
    }

    public function digest(string $value, string $context = 'recipient'): string
    {
        $context = self::normalizeContext($context);

        if ($value === '' || strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new InvalidArgumentException('The HMAC value is invalid.');
        }

        return hash_hmac('sha256', $context . "\0" . $value, $this->siteSecret);
    }

    public function verify(string $value, string $digest, string $context = 'recipient'): bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            return false;
        }

        return hash_equals($this->digest($value, $context), $digest);
    }

    private static function normalizeContext(string $context): string
    {
        $context = strtolower(trim($context));

        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $context) !== 1 || strlen($context) > self::MAX_CONTEXT_LENGTH) {
            throw new InvalidArgumentException('The HMAC context is invalid.');
        }

        return $context;
    }

    private static function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
