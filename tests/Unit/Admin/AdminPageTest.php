<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\AdminPage;
use OneSMTP\Core\Capabilities;
use OneSMTP\Plugin;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdminPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_actions'] = [];
        $GLOBALS['onesmtp_test_admin_menu_pages'] = [];
        $GLOBALS['onesmtp_test_admin_submenu_pages'] = [];
        $GLOBALS['onesmtp_test_current_user_can'] = true;
        $GLOBALS['wpdb'] = new FakeWpdb();
        unset($GLOBALS['onesmtp_test_wp_die']);
    }

    public function test_register_hooks_adds_admin_menu_callback(): void
    {
        $page = new AdminPage();

        $page->registerHooks();

        self::assertSame('admin_menu', $GLOBALS['onesmtp_test_actions'][0]['hook']);
        self::assertSame([$page, 'registerMenu'], $GLOBALS['onesmtp_test_actions'][0]['callback']);
    }

    public function test_plugin_boot_registers_admin_menu_hook(): void
    {
        $plugin = new Plugin();

        $plugin->boot();

        $hooks = array_column($GLOBALS['onesmtp_test_actions'], 'hook');

        self::assertContains('admin_menu', $hooks);
    }

    public function test_register_menu_uses_manage_plugin_capability(): void
    {
        $page = new AdminPage();

        $page->registerMenu();

        self::assertSame(Capabilities::MANAGE_PLUGIN, $GLOBALS['onesmtp_test_admin_menu_pages'][0]['capability']);
        self::assertSame('onesmtp', $GLOBALS['onesmtp_test_admin_menu_pages'][0]['menu_slug']);
        self::assertSame(Capabilities::MANAGE_PLUGIN, $GLOBALS['onesmtp_test_admin_submenu_pages'][0]['capability']);
        self::assertSame('onesmtp', $GLOBALS['onesmtp_test_admin_submenu_pages'][0]['parent_slug']);
    }

    public function test_render_outputs_stable_admin_sections_for_managers(): void
    {
        $page = new AdminPage();

        ob_start();
        $page->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('nav-tab-wrapper', $output);
        self::assertStringContainsString('onesmtp-admin-hero', $output);
        self::assertStringContainsString('onesmtp-admin-hero-rail', $output);
        self::assertStringContainsString('onesmtp-admin-section-header', $output);
        self::assertStringContainsString('id="onesmtp-general"', $output);
        self::assertStringContainsString('id="onesmtp-setup"', $output);
        self::assertStringContainsString('id="onesmtp-providers"', $output);
        self::assertStringContainsString('id="onesmtp-routing"', $output);
        self::assertStringContainsString('id="onesmtp-settings"', $output);
        self::assertStringContainsString('id="onesmtp-logs"', $output);
        self::assertStringContainsString('id="onesmtp-tools"', $output);
        self::assertStringContainsString('id="onesmtp-diagnostics"', $output);
        self::assertStringContainsString('id="onesmtp-alerts"', $output);
        self::assertStringContainsString('Reliable email delivery for WordPress.', $output);
        self::assertStringContainsString('A premium, enterprise-ready admin workspace', $output);
        self::assertStringContainsString('General / Setup', $output);
        self::assertStringContainsString('Email Control / Routing', $output);
        self::assertStringContainsString('Email Logs', $output);
        self::assertStringContainsString('Tools', $output);
    }

    public function test_render_blocks_users_without_manage_capability(): void
    {
        $GLOBALS['onesmtp_test_current_user_can'] = false;
        $page = new AdminPage();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to access OneSMTP settings.');

        ob_start();

        try {
            $page->render();
        } finally {
            ob_end_clean();
        }
    }
}
