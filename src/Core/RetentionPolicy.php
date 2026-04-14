<?php

declare(strict_types=1);

namespace OneSMTP\Core;

final class RetentionPolicy
{
    private const DEFAULT_DAYS = 30;
    private const MAX_DAYS     = 120;

    /**
     * Returns log retention in days.
     *
     * Filter: onesmtp_log_retention_days
     */
    public static function getLogRetentionDays(): int
    {
        $storedDays = (int) get_option('onesmtp_log_retention_days', self::DEFAULT_DAYS);
        $days       = (int) apply_filters('onesmtp_log_retention_days', $storedDays);

        return self::normalizeDays($days);
    }

    public static function normalizeDays(int $days): int
    {
        if ($days < 1) {
            $days = self::DEFAULT_DAYS;
        }

        if ($days > self::MAX_DAYS) {
            $days = self::MAX_DAYS;
        }

        return $days;
    }
}
