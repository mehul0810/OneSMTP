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

    public function test_retry_cap_semantics_currently_safe_for_attempts_above_six(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertNull($policy->chooseNextProvider(10, 7, []));
    }

    public function test_two_failure_switch_semantics_are_pending_concrete_policy(): void
    {
        self::markTestIncomplete('TODO: Assert switch-to-next-provider behavior when DefaultDispatchPolicy implements failover context handling.');
    }
}
