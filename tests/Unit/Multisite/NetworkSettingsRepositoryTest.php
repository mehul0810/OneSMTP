<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Multisite;

use OneSMTP\Multisite\NetworkSettingsRepository;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Settings\BackgroundSendingSettingsRepository;
use OneSMTP\Settings\RateLimitSettingsRepository;
use PHPUnit\Framework\TestCase;

final class NetworkSettingsRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['onesmtp_test_multisite'] = true;
        $GLOBALS['onesmtp_test_network_admin'] = true;
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_current_user_caps'] = ['manage_network_options' => true];
        $GLOBALS['onesmtp_test_current_blog_id'] = 1;
        $GLOBALS['onesmtp_test_blog_stack'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['onesmtp_test_multisite'], $GLOBALS['onesmtp_test_network_admin'], $GLOBALS['onesmtp_test_options'], $GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_current_blog_id'], $GLOBALS['onesmtp_test_blog_stack'], $GLOBALS['onesmtp_test_throw_on_update_option']);
    }

    public function test_network_defaults_are_allowlisted_and_resolved_only_when_inherited(): void
    {
        $gate = new FeatureGate([FeatureGate::MULTISITE_MANAGEMENT => true], true);
        $repository = new NetworkSettingsRepository($gate);

        self::assertTrue($repository->saveNetwork(
            [
                NetworkSettingsRepository::RATE_LIMITS => [
					'per_minute' => 11,
					'per_hour' => 22,
					'per_day' => 33,
				],
                NetworkSettingsRepository::BACKGROUND_SENDING => ['enabled' => true],
                'provider_credentials' => ['password' => 'must-not-persist'],
            ],
            [
				NetworkSettingsRepository::RATE_LIMITS => true,
				NetworkSettingsRepository::BACKGROUND_SENDING => true,
			]
        ));

        $stored = get_site_option(NetworkSettingsRepository::NETWORK_OPTION, []);
        self::assertArrayNotHasKey('provider_credentials', $stored['defaults']);
        self::assertSame(11, $stored['defaults'][ NetworkSettingsRepository::RATE_LIMITS ]['per_minute']);

        update_option(NetworkSettingsRepository::SITE_OPTION, [
            'inheritance' => [NetworkSettingsRepository::RATE_LIMITS => true],
            'overrides' => [NetworkSettingsRepository::RATE_LIMITS => ['per_minute' => 99]],
        ], false);
        self::assertSame(11, $repository->resolve(NetworkSettingsRepository::RATE_LIMITS, ['per_minute' => 1])['per_minute']);

        update_option(NetworkSettingsRepository::SITE_OPTION, [
            'inheritance' => [NetworkSettingsRepository::RATE_LIMITS => false],
            'overrides' => [NetworkSettingsRepository::RATE_LIMITS => ['per_minute' => 99]],
        ], false);
        self::assertSame(99, $repository->resolve(NetworkSettingsRepository::RATE_LIMITS, ['per_minute' => 1])['per_minute']);
    }

    public function test_single_site_and_disabled_pro_are_default_deny(): void
    {
        $repository = new NetworkSettingsRepository(new FeatureGate([], false));
        update_option(NetworkSettingsRepository::NETWORK_OPTION, [
            'defaults' => [NetworkSettingsRepository::RATE_LIMITS => ['per_minute' => 77]],
            'default_inheritance' => [NetworkSettingsRepository::RATE_LIMITS => true],
        ], false);

        $GLOBALS['onesmtp_test_multisite'] = false;
        self::assertSame(0, (new RateLimitSettingsRepository(null, $repository))->get()->getPerMinute());

        $GLOBALS['onesmtp_test_multisite'] = true;
        self::assertSame(false, (new BackgroundSendingSettingsRepository(null, $repository))->get()->isEnabled());
    }

    public function test_save_site_restores_original_blog_when_update_throws(): void
    {
        $gate = new FeatureGate([FeatureGate::MULTISITE_MANAGEMENT => true], true);
        $repository = new NetworkSettingsRepository($gate);
        $GLOBALS['onesmtp_test_current_blog_id'] = 77;
        $GLOBALS['onesmtp_test_throw_on_update_option'] = NetworkSettingsRepository::SITE_OPTION;

        try {
            $repository->saveSite(2, [], []);
            self::fail('Expected the synthetic site-option write to throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic update_option failure.', $exception->getMessage());
        }

        self::assertSame(77, get_current_blog_id());
        self::assertSame([], $GLOBALS['onesmtp_test_blog_stack']);
    }
}
