<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Dispatch;

use OneSMTP\Dispatch\DefaultDispatchPolicy;
use PHPUnit\Framework\TestCase;

final class DefaultDispatchPolicyTest extends TestCase
{
    public function test_returns_null_for_unimplemented_selection_logic_placeholder(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertNull($policy->chooseNextProvider(10, 1, []));
    }

    public function test_retry_cap_semantics_placeholder_for_attempts_above_six(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertNull($policy->chooseNextProvider(10, 7, []));
        self::markTestIncomplete('TODO: Replace with explicit terminal-state assertion when retry-cap policy is implemented.');
    }
}
