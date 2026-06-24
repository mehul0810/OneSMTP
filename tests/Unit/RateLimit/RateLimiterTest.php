<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\RateLimit;

use OneSMTP\RateLimit\RateLimiter;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Settings\RateLimitSettingsRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private const NOW = 1782302400;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_transients'] = [];
        $GLOBALS['onesmtp_test_object_cache'] = [];
    }

    public function test_disabled_limits_allow_sends(): void
    {
        $decision = $this->limiter()->evaluate();

        self::assertTrue($decision->canSend());
    }

    public function test_per_minute_limit_allows_just_under_boundary(): void
    {
        update_option('onesmtp_settings', [
            'rate_limits' => [
                'per_minute' => 3,
            ],
        ], false);
        $this->seedWindowStats(60, 2, 50);

        $decision = $this->limiter()->evaluate();

        self::assertTrue($decision->canSend());
    }

    public function test_per_minute_limit_defers_at_limit_boundary(): void
    {
        update_option('onesmtp_settings', [
            'rate_limits' => [
                'per_minute' => 3,
            ],
        ], false);
        $this->seedWindowStats(60, 3, 50);

        $decision = $this->limiter()->evaluate();

        self::assertFalse($decision->canSend());
        self::assertSame('minute', $decision->getWindow());
        self::assertSame(3, $decision->getLimit());
        self::assertSame(3, $decision->getUsed());
        self::assertSame(10, $decision->getRetryAfter());
    }

    public function test_per_minute_limit_defers_over_limit_boundary(): void
    {
        update_option('onesmtp_settings', [
            'rate_limits' => [
                'per_minute' => 3,
            ],
        ], false);
        $this->seedWindowStats(60, 4, 45);

        $decision = $this->limiter()->evaluate();

        self::assertFalse($decision->canSend());
        self::assertSame('minute', $decision->getWindow());
        self::assertSame(15, $decision->getRetryAfter());
    }

    public function test_hourly_and_daily_limits_are_evaluated_when_configured(): void
    {
        update_option('onesmtp_settings', [
            'rate_limits' => [
                'per_hour' => 10,
                'per_day' => 100,
            ],
        ], false);
        $this->seedWindowStats(HOUR_IN_SECONDS, 10, 3500);
        $this->seedWindowStats(DAY_IN_SECONDS, 90, 80000);

        $decision = $this->limiter()->evaluate();

        self::assertFalse($decision->canSend());
        self::assertSame('hour', $decision->getWindow());
        self::assertSame(100, $decision->getRetryAfter());
    }

    public function test_longest_exhausted_window_controls_retry_after(): void
    {
        update_option('onesmtp_settings', [
            'rate_limits' => [
                'per_minute' => 3,
                'per_hour' => 10,
                'per_day' => 100,
            ],
        ], false);
        $this->seedWindowStats(60, 3, 55);
        $this->seedWindowStats(HOUR_IN_SECONDS, 10, 3590);
        $this->seedWindowStats(DAY_IN_SECONDS, 100, 86000);

        $decision = $this->limiter()->evaluate();

        self::assertFalse($decision->canSend());
        self::assertSame('day', $decision->getWindow());
        self::assertSame(400, $decision->getRetryAfter());
    }

    public function test_configured_limits_use_send_lock_for_parallel_workers(): void
    {
        update_option('onesmtp_settings', [
            'rate_limits' => [
                'per_minute' => 3,
            ],
        ], false);
        set_transient('rate_limit_send_lock', 1, 60);

        $limiter = $this->limiter();

        self::assertFalse($limiter->acquireSendLock());

        $limiter->releaseSendLock();

        self::assertTrue($limiter->acquireSendLock());
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter(
            new AttemptRepository(),
            new RateLimitSettingsRepository(),
            static fn (): int => self::NOW
        );
    }

    private function seedWindowStats(int $seconds, int $count, int $oldestAge): void
    {
        $since = gmdate('Y-m-d H:i:s', self::NOW - $seconds);
        $GLOBALS['wpdb']->successfulSendWindowStatsBySince[$since] = [
            'sent_count' => $count,
            'oldest_created_at' => gmdate('Y-m-d H:i:s', self::NOW - $oldestAge),
        ];
    }
}
