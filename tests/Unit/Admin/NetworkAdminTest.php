<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\NetworkAdmin;
use OneSMTP\Core\Capabilities;
use OneSMTP\Multisite\NetworkSettingsRepository;
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
        $GLOBALS['onesmtp_test_current_blog_id'] = 1;
        $GLOBALS['onesmtp_test_blog_stack'] = [];
        $GLOBALS['wpdb'] = new FakeWpdb();
        unset($GLOBALS['onesmtp_test_wp_die']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['onesmtp_test_multisite'], $GLOBALS['onesmtp_test_network_admin'], $GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_sites'], $GLOBALS['onesmtp_test_current_blog_id'], $GLOBALS['onesmtp_test_blog_stack'], $GLOBALS['onesmtp_test_throw_on_get_option'], $GLOBALS['onesmtp_test_throw_on_get_bloginfo'], $GLOBALS['wpdb']);
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

    public function test_fresh_site_uses_network_default_inheritance_and_preserves_unchanged_values(): void
    {
        $GLOBALS['onesmtp_test_sites'] = [2];
        $GLOBALS['onesmtp_test_blog_names'] = [2 => 'Fresh site'];
        $GLOBALS['onesmtp_test_options'] = [
            NetworkSettingsRepository::NETWORK_OPTION => [
                'value' => [
                    'defaults' => [
                        NetworkSettingsRepository::RATE_LIMITS => [
                            'per_minute' => 99,
                            'per_hour' => 999,
                            'per_day' => 9999,
                        ],
                    ],
                    'default_inheritance' => [NetworkSettingsRepository::RATE_LIMITS => false],
                ],
                'autoload' => false,
            ],
            'onesmtp_settings' => [
                'value' => [
                    NetworkSettingsRepository::RATE_LIMITS => [
                        'per_minute' => 17,
                        'per_hour' => 170,
                        'per_day' => 1700,
                    ],
                ],
                'autoload' => false,
            ],
        ];
        $gate = new FeatureGate([FeatureGate::MULTISITE_MANAGEMENT => true], true);
        $settings = new NetworkSettingsRepository($gate);
        $html = '';
        ob_start();
        (new NetworkAdmin(featureGate: $gate))->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('name="site_inherit_rate_limits" value="1"', $html);
        self::assertStringNotContainsString('name="site_inherit_rate_limits" value="1" checked="checked"', $html);
        self::assertStringContainsString('name="site_rate_limit_per_minute" value="17"', $html);
        self::assertSame(17, $settings->resolve(NetworkSettingsRepository::RATE_LIMITS, ['per_minute' => 17])['per_minute']);

        self::assertTrue($settings->saveSite(
            2,
            [
                NetworkSettingsRepository::RATE_LIMITS => [
                    'per_minute' => 17,
                    'per_hour' => 170,
                    'per_day' => 1700,
                ],
            ],
            [NetworkSettingsRepository::RATE_LIMITS => false]
        ));
        self::assertSame(17, $settings->resolve(NetworkSettingsRepository::RATE_LIMITS, ['per_minute' => 17])['per_minute']);
    }

    public function test_read_site_restores_original_blog_when_get_site_throws(): void
    {
        $GLOBALS['onesmtp_test_sites'] = [2];
        $GLOBALS['onesmtp_test_current_blog_id'] = 77;
        $GLOBALS['onesmtp_test_throw_on_get_option'] = NetworkSettingsRepository::SITE_OPTION;

        try {
            (new NetworkAdmin(featureGate: new FeatureGate([FeatureGate::MULTISITE_MANAGEMENT => true], true)))->render();
            self::fail('Expected the synthetic site read to throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic get_option failure.', $exception->getMessage());
        }

        self::assertSame(77, get_current_blog_id());
        self::assertSame([], $GLOBALS['onesmtp_test_blog_stack']);
    }

    public function test_read_site_restores_original_blog_when_bloginfo_throws(): void
    {
        $GLOBALS['onesmtp_test_sites'] = [2];
        $GLOBALS['onesmtp_test_current_blog_id'] = 77;
        $GLOBALS['onesmtp_test_throw_on_get_bloginfo'] = true;

        try {
            (new NetworkAdmin(featureGate: new FeatureGate([FeatureGate::MULTISITE_MANAGEMENT => true], true)))->render();
            self::fail('Expected the synthetic blog-name read to throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic get_bloginfo failure.', $exception->getMessage());
        }

        self::assertSame(77, get_current_blog_id());
        self::assertSame([], $GLOBALS['onesmtp_test_blog_stack']);
    }
}
