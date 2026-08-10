<?php

declare(strict_types=1);

namespace OneSMTP\Core;

final class Installer
{
    public const VERSION_OPTION       = 'onesmtp_version';
    public const SCHEMA_VERSION_OPTION = 'onesmtp_schema_version';
    public const RETENTION_DAYS_OPTION = 'onesmtp_log_retention_days';
    public const SCHEMA_VERSION        = 3;

    public static function activate(): void
    {
        if (! self::ensureSchema()) {
            return;
        }

        Capabilities::provisionDefaults();
        self::storeDefaults();
    }

    public static function maybeUpgrade(): void
    {
        if (! defined('ABSPATH')) {
            return;
        }

        $version = self::currentVersion();
        $stored  = (string) get_option(self::VERSION_OPTION, '');

        if (! self::ensureSchema()) {
            return;
        }

        if ($stored !== $version) {
            update_option(self::VERSION_OPTION, $version, false);
        }
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
        $version = self::currentVersion();

        update_option(self::VERSION_OPTION, $version, false);

        if (get_option(self::RETENTION_DAYS_OPTION, null) === null) {
            add_option(self::RETENTION_DAYS_OPTION, RetentionPolicy::normalizeDays(30), '', false);
        }
    }

    private static function currentVersion(): string
    {
        return defined('ONESMTP_VERSION') ? (string) constant('ONESMTP_VERSION') : '0.1.0';
    }

    private static function ensureSchema(): bool
    {
        if ((int) get_option(self::SCHEMA_VERSION_OPTION, 0) === self::SCHEMA_VERSION
            && DatabaseSchema::verifyRequiredTables()
            && DatabaseSchema::verifyRequiredColumns()
        ) {
            return true;
        }

        DatabaseSchema::createTables();
        if ( ! DatabaseSchema::verifyRequiredTables() || ! DatabaseSchema::verifyRequiredColumns() ) {
            return false;
        }

        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false);

        return true;
    }
}
