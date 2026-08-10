<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Core;

use OneSMTP\Core\RetentionPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['onesmtp_test_filters'] = [];
        $GLOBALS['onesmtp_test_options'] = [];
    }

    public function test_returns_default_days_when_no_filter_is_registered(): void
    {
        self::assertSame(30, RetentionPolicy::getLogRetentionDays());
    }

    public function test_clamps_value_above_maximum_to_120_days(): void
    {
        add_filter('onesmtp_log_retention_days', static fn (): int => 365);

        self::assertSame(120, RetentionPolicy::getLogRetentionDays());
    }

    public function test_resets_non_positive_values_to_default_30_days(): void
    {
        add_filter('onesmtp_log_retention_days', static fn (): int => 0);
        self::assertSame(30, RetentionPolicy::getLogRetentionDays());

        $GLOBALS['onesmtp_test_filters'] = [];
        add_filter('onesmtp_log_retention_days', static fn (): int => -5);
        self::assertSame(30, RetentionPolicy::getLogRetentionDays());
    }

    public function test_keeps_value_within_allowed_bounds(): void
    {
        add_filter('onesmtp_log_retention_days', static fn (): int => 90);

        self::assertSame(90, RetentionPolicy::getLogRetentionDays());
    }

    public function test_resolves_named_presets_and_bounded_custom_duration(): void
    {
        self::assertSame(7, RetentionPolicy::daysForProfile('short'));
        self::assertSame(30, RetentionPolicy::daysForProfile('standard'));
        self::assertSame(90, RetentionPolicy::daysForProfile('extended'));
        self::assertSame(120, RetentionPolicy::daysForProfile('maximum'));
        self::assertSame(45, RetentionPolicy::daysForProfile('custom', 45));
        self::assertSame('custom', RetentionPolicy::profileForDays(45));
    }

    public function test_rejects_custom_duration_outside_one_to_120_days(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetentionPolicy::daysForProfile('custom', 121);
    }

    public function test_saves_site_local_duration_for_the_pruner(): void
    {
        RetentionPolicy::saveDays(90);

        self::assertSame(90, get_option(RetentionPolicy::OPTION));
        self::assertSame(90, RetentionPolicy::getLogRetentionDays());
    }
}
