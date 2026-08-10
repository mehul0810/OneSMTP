<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Core;

use OneSMTP\Core\Installer;
use OneSMTP\Core\DatabaseSchema;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->existingTables = DatabaseSchema::requiredTables();
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_dbdelta_queries'] = [];
        unset($GLOBALS['onesmtp_test_throw_on_update_option'], $GLOBALS['onesmtp_test_throw_on_get_option']);
        $GLOBALS['onesmtp_test_roles'] = [
            'administrator' => new class {
                /** @var array<string,bool> */
                public array $caps = [];

                public function add_cap(string $capability): void
                {
                    $this->caps[$capability] = true;
                }

                public function remove_cap(string $capability): void
                {
                    unset($this->caps[$capability]);
                }
            },
        ];

        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/onesmtp-wp/');
        }

        $upgradeDir = ABSPATH . 'wp-admin/includes';
        if (! is_dir($upgradeDir)) {
            mkdir($upgradeDir, 0777, true);
        }

        $upgradeFile = $upgradeDir . '/upgrade.php';
        if (! file_exists($upgradeFile)) {
            file_put_contents($upgradeFile, "<?php\n");
        }
    }

    public function test_activate_creates_schema_and_stores_repeatable_defaults(): void
    {
        Installer::activate();

        self::assertCount(count(DatabaseSchema::requiredTables()), $GLOBALS['onesmtp_test_dbdelta_queries']);
        self::assertSame((string) constant('ONESMTP_VERSION'), get_option(Installer::VERSION_OPTION));
        self::assertSame(Installer::SCHEMA_VERSION, get_option(Installer::SCHEMA_VERSION_OPTION));
        self::assertSame(30, get_option(Installer::RETENTION_DAYS_OPTION));

        $GLOBALS['onesmtp_test_options'][Installer::VERSION_OPTION]['value'] = '0.0.1';
        $GLOBALS['onesmtp_test_options'][Installer::RETENTION_DAYS_OPTION]['value'] = 90;

        Installer::activate();

        self::assertCount(count(DatabaseSchema::requiredTables()), $GLOBALS['onesmtp_test_dbdelta_queries']);
        self::assertSame((string) constant('ONESMTP_VERSION'), get_option(Installer::VERSION_OPTION));
        self::assertSame(Installer::SCHEMA_VERSION, get_option(Installer::SCHEMA_VERSION_OPTION));
        self::assertSame(90, get_option(Installer::RETENTION_DAYS_OPTION));
    }

    public function test_uninstall_preserves_options_by_default(): void
    {
        Installer::activate();

        Installer::uninstall();

        self::assertSame((string) constant('ONESMTP_VERSION'), get_option(Installer::VERSION_OPTION));
        self::assertSame(Installer::SCHEMA_VERSION, get_option(Installer::SCHEMA_VERSION_OPTION));
        self::assertSame(30, get_option(Installer::RETENTION_DAYS_OPTION));
    }

    public function test_maybe_upgrade_migrates_existing_current_plugin_version_when_schema_is_missing(): void
    {
        // Production installs on this branch have plugin version 0.3.0. The
        // schema migration must not depend on a plugin-version change.
        $GLOBALS['onesmtp_test_options'][Installer::VERSION_OPTION]['value'] = '0.3.0';

        Installer::maybeUpgrade();

        self::assertCount(count(DatabaseSchema::requiredTables()), $GLOBALS['onesmtp_test_dbdelta_queries']);
        self::assertSame((string) constant('ONESMTP_VERSION'), get_option(Installer::VERSION_OPTION));
        self::assertSame(Installer::SCHEMA_VERSION, get_option(Installer::SCHEMA_VERSION_OPTION));

        Installer::maybeUpgrade();

        self::assertCount(count(DatabaseSchema::requiredTables()), $GLOBALS['onesmtp_test_dbdelta_queries']);
    }

    public function test_maybe_upgrade_migrates_old_schema_even_when_plugin_version_is_current(): void
    {
        $GLOBALS['onesmtp_test_options'][Installer::VERSION_OPTION]['value'] = (string) constant('ONESMTP_VERSION');
        $GLOBALS['onesmtp_test_options'][Installer::SCHEMA_VERSION_OPTION]['value'] = Installer::SCHEMA_VERSION - 1;

        Installer::maybeUpgrade();

        self::assertCount(count(DatabaseSchema::requiredTables()), $GLOBALS['onesmtp_test_dbdelta_queries']);
        self::assertSame(Installer::SCHEMA_VERSION, get_option(Installer::SCHEMA_VERSION_OPTION));
    }

    public function test_maybe_upgrade_is_noop_when_plugin_and_schema_versions_are_current(): void
    {
        $GLOBALS['onesmtp_test_options'][Installer::VERSION_OPTION]['value'] = (string) constant('ONESMTP_VERSION');
        $GLOBALS['onesmtp_test_options'][Installer::SCHEMA_VERSION_OPTION]['value'] = Installer::SCHEMA_VERSION;

        Installer::maybeUpgrade();

        self::assertCount(0, $GLOBALS['onesmtp_test_dbdelta_queries']);
    }

    public function test_maybe_upgrade_does_not_mark_schema_when_schema_option_write_fails(): void
    {
        $GLOBALS['onesmtp_test_throw_on_update_option'] = Installer::SCHEMA_VERSION_OPTION;

        try {
            Installer::maybeUpgrade();
            self::fail('Expected the synthetic schema option write to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic update_option failure.', $exception->getMessage());
        }

        self::assertFalse(get_option(Installer::SCHEMA_VERSION_OPTION, false));
        self::assertCount(count(DatabaseSchema::requiredTables()), $GLOBALS['onesmtp_test_dbdelta_queries']);
    }

    public function test_partial_schema_verification_leaves_migration_stale_and_retries(): void
    {
        $GLOBALS['onesmtp_test_options'][Installer::VERSION_OPTION]['value'] = '0.3.0';
        $GLOBALS['wpdb']->existingTables = array_values(array_filter(
            DatabaseSchema::requiredTables(),
            static fn (string $table): bool => ! str_ends_with($table, 'provider_event_replays')
        ));
        $GLOBALS['wpdb']->existingTables[] = 'wp_onesmtp_provider_event_replays_shadow';

        Installer::maybeUpgrade();

        self::assertFalse(get_option(Installer::SCHEMA_VERSION_OPTION, false));
        self::assertSame('0.3.0', get_option(Installer::VERSION_OPTION));
        self::assertCount(count(DatabaseSchema::requiredTables()), $GLOBALS['onesmtp_test_dbdelta_queries']);

        $GLOBALS['wpdb']->existingTables = DatabaseSchema::requiredTables();
        Installer::maybeUpgrade();

        self::assertSame(Installer::SCHEMA_VERSION, get_option(Installer::SCHEMA_VERSION_OPTION));
        self::assertSame((string) constant('ONESMTP_VERSION'), get_option(Installer::VERSION_OPTION));
        self::assertCount(count(DatabaseSchema::requiredTables()) * 2, $GLOBALS['onesmtp_test_dbdelta_queries']);
    }
}
