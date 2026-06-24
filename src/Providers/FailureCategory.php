<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

final class FailureCategory
{
    public const RETRYABLE      = 'retryable';
    public const TERMINAL       = 'terminal';
    public const AUTHENTICATION = 'authentication';
    public const QUOTA          = 'quota';
    public const TIMEOUT        = 'timeout';
    public const POLICY         = 'policy';

    private const CATEGORIES = [
        self::RETRYABLE,
        self::TERMINAL,
        self::AUTHENTICATION,
        self::QUOTA,
        self::TIMEOUT,
        self::POLICY,
    ];

    public static function normalize(?string $category): string
    {
        $category = sanitize_key((string) $category);

        return in_array($category, self::CATEGORIES, true) ? $category : self::RETRYABLE;
    }

    public static function shouldRetry(?string $category): bool
    {
        return in_array(
            self::normalize($category),
            [self::RETRYABLE, self::QUOTA, self::TIMEOUT],
            true
        );
    }
}
