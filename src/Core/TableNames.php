<?php

declare(strict_types=1);

namespace OneSMTP\Core;

final class TableNames
{
    public static function providers(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'onesmtp_providers';
    }

    public static function messages(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'onesmtp_messages';
    }

    public static function attempts(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'onesmtp_attempts';
    }

    public static function events(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'onesmtp_events';
    }

    public static function quotaLeases(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'onesmtp_quota_leases';
    }

    public static function providerEvents(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'onesmtp_provider_events';
    }

    public static function providerEventReplays(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'onesmtp_provider_event_replays';
    }

    public static function suppressions(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'onesmtp_suppressions';
    }

    public static function suppressionDerivations(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'onesmtp_suppression_derivations';
    }
}
