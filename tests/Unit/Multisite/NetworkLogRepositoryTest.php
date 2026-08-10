<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Multisite;

use OneSMTP\Multisite\NetworkLogRepository;
use OneSMTP\Multisite\NetworkSettingsRepository;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class NetworkLogRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['onesmtp_test_multisite'] = true;
        $GLOBALS['onesmtp_test_network_admin'] = true;
        $GLOBALS['onesmtp_test_current_user_caps'] = ['manage_network_options' => true];
        $GLOBALS['onesmtp_test_sites'] = [1, 2];
        $GLOBALS['onesmtp_test_current_blog_id'] = 1;
        $GLOBALS['onesmtp_test_blog_names'] = [
			1 => 'Primary site',
			2 => 'Store site',
		];
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->recentMessageRows = [
            [
                'id' => 9,
                'message_uuid' => 'network-lineage-9',
                'payload_json' => wp_json_encode([
                    'to' => ['person@example.test'],
                    'subject' => 'private subject',
                    'message' => 'private body',
                    'headers' => ['Authorization: Bearer secret-token'],
                ]),
                'status' => 'sent',
                'selected_provider_id' => 0,
                'current_attempt' => 1,
                'max_attempts' => 6,
                'attempt_count' => 1,
                'switch_count' => 0,
                'created_at' => '2026-08-10 10:00:00',
                'updated_at' => '2026-08-10 10:01:00',
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['onesmtp_test_multisite'], $GLOBALS['onesmtp_test_network_admin'], $GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_sites'], $GLOBALS['onesmtp_test_current_blog_id'], $GLOBALS['onesmtp_test_blog_names'], $GLOBALS['wpdb']);
    }

    public function test_network_rows_are_bounded_and_privacy_safe(): void
    {
        $gate = new FeatureGate([FeatureGate::MULTISITE_MANAGEMENT => true], true);
        $settings = new NetworkSettingsRepository($gate);
        $repository = new NetworkLogRepository($settings, $gate);

        self::assertTrue($settings->isAvailable());
        self::assertTrue(\OneSMTP\Core\Capabilities::canViewNetworkLogs($gate));
        self::assertSame([1, 2], $repository->siteIds());

        $rows = $repository->listFiltered([], 1, 500);

        self::assertCount(2, $rows);
        self::assertSame([1, 2], array_column($rows, 'site_id'));
        self::assertSame(['Primary site', 'Store site'], array_values(array_unique(array_column($rows, 'site_name'))));
        foreach ($rows as $row) {
            self::assertStringContainsString('example.test', $row['recipients']);
            self::assertStringNotContainsString('person@example.test', $row['recipients']);
            self::assertStringNotContainsString('private subject', (string) wp_json_encode($row));
            self::assertStringNotContainsString('private body', (string) wp_json_encode($row));
            self::assertArrayNotHasKey('payload_json', $row);
        }
        self::assertLessThanOrEqual(50, count($repository->listFiltered([], 1, 500)));
    }

    public function test_network_view_is_default_deny_outside_network_admin(): void
    {
        $GLOBALS['onesmtp_test_network_admin'] = false;
        $repository = new NetworkLogRepository(new NetworkSettingsRepository(new FeatureGate([FeatureGate::MULTISITE_MANAGEMENT => true], true)));

        self::assertSame([], $repository->listFiltered());
    }
}
