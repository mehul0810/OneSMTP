<?php

declare(strict_types=1);

namespace OneSMTP\Core;

final class Installer
{
    public static function activate(): void
    {
        DatabaseSchema::createTables();
        self::storeDefaults();
    }

    private static function storeDefaults(): void
    {
        add_option('onesmtp_version', ONESMTP_VERSION);
        add_option('onesmtp_log_retention_days', RetentionPolicy::normalizeDays(30));
    }
}
