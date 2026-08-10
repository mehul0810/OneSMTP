<?php

declare(strict_types=1);

namespace OneSMTP\Core;

use InvalidArgumentException;

final class RetentionPolicy
{
    public const OPTION       = 'onesmtp_log_retention_days';
    public const DEFAULT_DAYS = 30;
    public const MIN_DAYS     = 1;
    public const MAX_DAYS     = 120;

    /**
     * Named policies keep the common choices easy to review while custom
     * values remain bounded by the same 1-120 day contract.
     *
     * @return array<string,array{label:string,days:int,description:string}>
     */
    public static function presets(): array
    {
        return [
            'short' => [
                'label' => __('Short (7 days)', 'onesmtp'),
                'days' => 7,
                'description' => __('Keep a short operational window.', 'onesmtp'),
            ],
            'standard' => [
                'label' => __('Standard (30 days)', 'onesmtp'),
                'days' => self::DEFAULT_DAYS,
                'description' => __('The default balance for delivery troubleshooting.', 'onesmtp'),
            ],
            'extended' => [
                'label' => __('Extended (90 days)', 'onesmtp'),
                'days' => 90,
                'description' => __('Keep a longer operational and audit window.', 'onesmtp'),
            ],
            'maximum' => [
                'label' => __('Maximum (120 days)', 'onesmtp'),
                'days' => self::MAX_DAYS,
                'description' => __('Keep the longest supported window.', 'onesmtp'),
            ],
        ];
    }

    /**
     * Returns log retention in days.
     *
     * Filter: onesmtp_log_retention_days
     */
    public static function getLogRetentionDays(): int
    {
        $storedDays = (int) get_option(self::OPTION, self::DEFAULT_DAYS);
        $days       = (int) apply_filters('onesmtp_log_retention_days', $storedDays);

        return self::normalizeDays($days);
    }

    /**
     * Save a validated site-local retention policy.
     *
     * The option deliberately uses update_option rather than a network
     * option: issue #44 does not broaden retention controls to multisite.
     */
    public static function saveDays(int $days): void
    {
        update_option(self::OPTION, self::validateDays($days), false);
    }

    /**
     * Resolve a named policy or a bounded custom duration.
     */
    public static function daysForProfile(string $profile, int $customDays = self::DEFAULT_DAYS): int
    {
        $profile = sanitize_key($profile);
        if ($profile === 'custom') {
            return self::validateDays($customDays);
        }

        $presets = self::presets();
        if ( ! isset($presets[ $profile ])) {
            throw new InvalidArgumentException('Choose a supported retention policy.');
        }

        return (int) $presets[ $profile ]['days'];
    }

    public static function profileForDays(int $days): string
    {
        $days = self::normalizeDays($days);
        foreach (self::presets() as $profile => $preset) {
            if ($days === (int) $preset['days']) {
                return $profile;
            }
        }

        return 'custom';
    }

    public static function validateDays(int $days): int
    {
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException('Retention must be between 1 and 120 days.');
        }

        return $days;
    }

    public static function normalizeDays(int $days): int
    {
        if ($days < self::MIN_DAYS) {
            $days = self::DEFAULT_DAYS;
        }

        if ($days > self::MAX_DAYS) {
            $days = self::MAX_DAYS;
        }

        return $days;
    }
}
