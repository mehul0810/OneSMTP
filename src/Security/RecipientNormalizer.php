<?php

declare(strict_types=1);

namespace OneSMTP\Security;

/**
 * Canonicalize one recipient without retaining or emitting the raw value.
 */
final class RecipientNormalizer
{
    private const MAX_RECIPIENT_LENGTH = 320;

    public function normalize(string $recipient): ?string
    {
        $value = trim($recipient);

        if ($value === '' || strlen($value) > self::MAX_RECIPIENT_LENGTH || self::hasControlCharacters($value)) {
            return null;
        }

        if (substr_count($value, '<') > 1 || substr_count($value, '>') > 1) {
            return null;
        }

        if (preg_match('/<([^<>]+)>$/D', $value, $matches) === 1) {
            $value = trim($matches[1]);
        }

        if (str_contains($value, ',') || str_contains($value, ';') || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return strtolower($value);
    }

    private static function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
