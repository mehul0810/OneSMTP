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
        unset($_GET['tab']);
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

        self::assertSame(Capabilities::MANAGE_PLUGIN, $GLOBALS['onesmtp_test_admin_submenu_pages'][0]['capability']);
        self::assertSame('options-general.php', $GLOBALS['onesmtp_test_admin_submenu_pages'][0]['parent_slug']);
        self::assertSame('onesmtp', $GLOBALS['onesmtp_test_admin_submenu_pages'][0]['menu_slug']);
    }

    public function test_render_outputs_stable_admin_sections_for_managers(): void
    {
        $page = new AdminPage();

        ob_start();
        $page->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('nav-tab-wrapper', $output);
        self::assertStringContainsString('data-onesmtp-workspaces', $output);
        self::assertStringContainsString('onesmtp-admin-header', $output);
        self::assertStringContainsString('onesmtp-overview-side-card', $output);
        self::assertStringContainsString('data-onesmtp-workspace-link="onesmtp-overview"', $output);
        self::assertStringContainsString('nav-tab nav-tab-active', $output);
        self::assertStringContainsString('aria-current="page"', $output);
        self::assertStringContainsString('id="onesmtp-overview"', $output);
        self::assertStringContainsString('Reliable email delivery for WordPress.', $output);
        self::assertStringContainsString('Setup needed', $output);
        self::assertStringContainsString('Overview', $output);
        self::assertStringContainsString('Routing', $output);
        self::assertStringNotContainsString('data-onesmtp-workspace-link="onesmtp-delivery"', $output);
        self::assertStringContainsString('Activity', $output);
        self::assertStringContainsString('Analytics', $output);
        self::assertStringContainsString('Settings', $output);
    }

    public function test_render_resolves_requested_tab_server_side(): void
    {
        $_GET['tab'] = 'onesmtp-analytics';
        $page = new AdminPage();

        ob_start();
        $page->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('id="onesmtp-analytics"', $output);
        self::assertStringContainsString('id="onesmtp-overview"', $output);
        self::assertStringContainsString('class="nav-tab nav-tab-active" data-onesmtp-workspace-link="onesmtp-analytics"', $output);
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
