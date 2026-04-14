<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Policy;

use OneSMTP\Tests\Support\PolicyFixtures;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function test_stops_after_max_six_retries_todo(): void
    {
        $context = PolicyFixtures::attemptContext([
            'attempt' => 6,
            'max_retries' => 6,
        ]);

        self::assertSame(6, $context['max_retries']);
        self::markTestIncomplete('TODO: Assert RetryPolicy::canRetry() returns false at attempt 6 terminal failure.');
    }

    public function test_increments_attempt_count_between_retries_todo(): void
    {
        $context = PolicyFixtures::attemptContext(['attempt' => 2]);

        self::assertSame(2, $context['attempt']);
        self::markTestIncomplete('TODO: Assert RetryPolicy increments attempts monotonically and never skips.');
    }

    public function test_marks_retryable_vs_non_retryable_failures_todo(): void
    {
        $transientContext = PolicyFixtures::attemptContext(['last_error_type' => 'timeout']);

        self::assertSame('timeout', $transientContext['last_error_type']);
        self::markTestIncomplete('TODO: Assert transient errors are retryable and hard auth failures are not.');
    }
}
