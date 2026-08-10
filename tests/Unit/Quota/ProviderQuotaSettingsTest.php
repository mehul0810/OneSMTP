<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Quota;

use OneSMTP\Quota\ProviderQuotaSettings;
use PHPUnit\Framework\TestCase;

final class ProviderQuotaSettingsTest extends TestCase
{
    public function test_normalizes_empty_negative_and_excessive_values_without_creating_unbounded_work(): void
    {
        $settings = ProviderQuotaSettings::fromArray([
            'per_minute' => '-4',
            'per_hour' => '999999999999999999999',
            'per_day' => 'not-a-number',
        ]);

        self::assertSame(0, $settings->getPerMinute());
        self::assertSame(ProviderQuotaSettings::MAX_LIMIT, $settings->getPerHour());
        self::assertSame(0, $settings->getPerDay());
        self::assertTrue($settings->hasAnyLimit());
    }

    public function test_zero_disables_windows_and_flat_provider_config_is_round_trippable(): void
    {
        $settings = new ProviderQuotaSettings(0, 12, 0);

        self::assertSame(1, count($settings->configuredWindows()));
        self::assertSame([
            'quota_per_minute' => 0,
            'quota_per_hour' => 12,
            'quota_per_day' => 0,
        ], $settings->toProviderConfig());
        self::assertSame($settings->toArray(), ProviderQuotaSettings::fromProviderConfig($settings->toProviderConfig())->toArray());
    }
}
