<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Core;

use OneSMTP\Core\Installer;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_dbdelta_queries'] = [];
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

        self::assertCount(5, $GLOBALS['onesmtp_test_dbdelta_queries']);
        self::assertSame((string) constant('ONESMTP_VERSION'), get_option(Installer::VERSION_OPTION));
        self::assertSame(30, get_option(Installer::RETENTION_DAYS_OPTION));

        $GLOBALS['onesmtp_test_options'][Installer::VERSION_OPTION]['value'] = '0.0.1';
        $GLOBALS['onesmtp_test_options'][Installer::RETENTION_DAYS_OPTION]['value'] = 90;

        Installer::activate();

        self::assertCount(10, $GLOBALS['onesmtp_test_dbdelta_queries']);
        self::assertSame((string) constant('ONESMTP_VERSION'), get_option(Installer::VERSION_OPTION));
        self::assertSame(90, get_option(Installer::RETENTION_DAYS_OPTION));
    }

    public function test_uninstall_preserves_options_by_default(): void
    {
        Installer::activate();

        Installer::uninstall();

        self::assertSame((string) constant('ONESMTP_VERSION'), get_option(Installer::VERSION_OPTION));
        self::assertSame(30, get_option(Installer::RETENTION_DAYS_OPTION));
    }

    public function test_maybe_upgrade_runs_schema_when_stored_version_is_stale(): void
    {
        $GLOBALS['onesmtp_test_options'][Installer::VERSION_OPTION]['value'] = '0.1.0-stale';

        Installer::maybeUpgrade();

        self::assertCount(5, $GLOBALS['onesmtp_test_dbdelta_queries']);
        self::assertSame((string) constant('ONESMTP_VERSION'), get_option(Installer::VERSION_OPTION));

        Installer::maybeUpgrade();

        self::assertCount(5, $GLOBALS['onesmtp_test_dbdelta_queries']);
    }
}
