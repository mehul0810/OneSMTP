<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Core;

use OneSMTP\Core\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['onesmtp_test_filters'] = [];
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
}
