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
}
