<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\NetworkAdmin;
use OneSMTP\Core\Capabilities;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NetworkAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_GET = ['page' => 'onesmtp-network'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $GLOBALS['onesmtp_test_multisite'] = true;
        $GLOBALS['onesmtp_test_network_admin'] = true;
        $GLOBALS['onesmtp_test_current_user_caps'] = ['manage_network_options' => true];
        $GLOBALS['onesmtp_test_sites'] = [];
        $GLOBALS['wpdb'] = new FakeWpdb();
        unset($GLOBALS['onesmtp_test_wp_die']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['onesmtp_test_multisite'], $GLOBALS['onesmtp_test_network_admin'], $GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_sites'], $GLOBALS['wpdb']);
    }

    public function test_network_page_is_default_deny_for_single_site(): void
    {
        $GLOBALS['onesmtp_test_multisite'] = false;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to access Aculect Mail network controls.');

        (new NetworkAdmin(featureGate: new FeatureGate([FeatureGate::MULTISITE_MANAGEMENT => true], true)))->render();
    }

    public function test_network_page_requires_network_capability_and_pro_gate(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'][ Capabilities::MANAGE_PLUGIN ] = false;
        $GLOBALS['onesmtp_test_current_user_caps']['manage_network_options'] = false;
        $this->expectException(RuntimeException::class);

        (new NetworkAdmin(featureGate: new FeatureGate([], false)))->render();
    }

    public function test_network_settings_render_has_safe_states_and_no_public_route(): void
    {
        $html = '';
        ob_start();
        (new NetworkAdmin(featureGate: new FeatureGate([FeatureGate::MULTISITE_MANAGEMENT => true], true)))->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Safe network defaults', $html);
        self::assertStringContainsString('Site inheritance and overrides', $html);
        self::assertStringContainsString('Network logs', $html);
        self::assertStringNotContainsString('onesmtp/v1', $html);
        self::assertStringContainsString('provider credentials', strtolower($html));
    }
}
