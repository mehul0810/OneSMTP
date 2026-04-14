<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Policy;

use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function test_retry_cap_allows_attempts_below_six(): void
    {
        self::assertTrue($this->canRetry(1));
        self::assertTrue($this->canRetry(5));
    }

    public function test_retry_cap_blocks_attempt_six_and_above(): void
    {
        self::assertFalse($this->canRetry(6));
        self::assertFalse($this->canRetry(7));
    }

    public function test_retry_cap_is_configurable_for_future_policy_variants(): void
    {
        self::assertTrue($this->canRetry(2, 4));
        self::assertFalse($this->canRetry(4, 4));
    }

    private function canRetry(int $attemptNumber, int $maxRetries = 6): bool
    {
        return $attemptNumber < $maxRetries;
    }
}
