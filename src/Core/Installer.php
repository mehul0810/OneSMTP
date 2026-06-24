<?php

declare(strict_types=1);

namespace OneSMTP\Core;

final class Installer
{
    public const VERSION_OPTION       = 'onesmtp_version';
    public const RETENTION_DAYS_OPTION = 'onesmtp_log_retention_days';

    public static function activate(): void
    {
        DatabaseSchema::createTables();
        Capabilities::provisionDefaults();
        self::storeDefaults();
    }

    public static function maybeUpgrade(): void
    {
        if (! defined('ABSPATH')) {
            return;
        }

        $version = defined('ONESMTP_VERSION') ? (string) constant('ONESMTP_VERSION') : '0.1.0';
        $stored  = (string) get_option(self::VERSION_OPTION, '');

        if ($stored === $version) {
            return;
        }

        DatabaseSchema::createTables();
        update_option(self::VERSION_OPTION, $version, false);
    }

    public static function deactivate(): void
    {
        Capabilities::revokeDefaults();
    }

    public static function uninstall(): void
    {
        /*
         * Preserve delivery records and settings by default. A destructive
         * uninstall path needs an explicit product decision and migration plan.
         */
    }

    private static function storeDefaults(): void
    {
        $version = defined('ONESMTP_VERSION') ? (string) constant('ONESMTP_VERSION') : '0.1.0';

        update_option(self::VERSION_OPTION, $version, false);

        if (get_option(self::RETENTION_DAYS_OPTION, null) === null) {
            add_option(self::RETENTION_DAYS_OPTION, RetentionPolicy::normalizeDays(30), '', false);
        }
    }
}
