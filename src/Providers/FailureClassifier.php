<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

final class FailureClassifier
{
    public static function classify(string $code = '', string $message = '', ?int $httpStatus = null): string
    {
        $code = strtolower($code);
        $message = strtolower($message);

        if ($httpStatus !== null && $httpStatus > 0) {
            return self::classifyHttpStatus($httpStatus, $message);
        }

        if (self::containsAny($code . ' ' . $message, ['timeout', 'timed out', 'deadline', 'operation timed'])) {
            return FailureCategory::TIMEOUT;
        }

        if (self::containsAny($code . ' ' . $message, ['quota', 'rate_limit', 'rate limit', 'too many requests', 'throttle'])) {
            return FailureCategory::QUOTA;
        }

        if (self::containsAny($code . ' ' . $message, ['auth', 'api_key', 'api key', 'token', 'credential', 'unauthorized', 'forbidden'])) {
            return FailureCategory::AUTHENTICATION;
        }

        if (self::containsAny($code . ' ' . $message, ['policy', 'spam', 'blocked', 'suppression', 'unsubscribe', 'rejected'])) {
            return FailureCategory::POLICY;
        }

        if (self::containsAny($code, ['invalid_recipient', 'invalid_sender', 'bad_request', 'missing_provider', 'ineligible_provider', 'no_provider'])) {
            return FailureCategory::TERMINAL;
        }

        if (self::containsAny($code, ['network_error', 'connection_error', 'temporary', 'unavailable'])) {
            return FailureCategory::RETRYABLE;
        }

        return FailureCategory::RETRYABLE;
    }

    private static function classifyHttpStatus(int $status, string $message): string
    {
        if (in_array($status, [408, 504], true)) {
            return FailureCategory::TIMEOUT;
        }

        if ($status === 401 || $status === 403) {
            return FailureCategory::AUTHENTICATION;
        }

        if ($status === 429) {
            return FailureCategory::QUOTA;
        }

        if (in_array($status, [400, 404, 422], true)) {
            return self::containsAny($message, ['policy', 'spam', 'blocked', 'suppression', 'unsubscribe', 'rejected'])
                ? FailureCategory::POLICY
                : FailureCategory::TERMINAL;
        }

        if ($status >= 500 && $status <= 599) {
            return FailureCategory::RETRYABLE;
        }

        return FailureCategory::TERMINAL;
    }

    /**
     * @param array<int,string> $needles
     */
    private static function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
