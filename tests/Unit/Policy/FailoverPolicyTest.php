<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Policy;

use OneSMTP\Dispatch\DefaultDispatchPolicy;
use PHPUnit\Framework\TestCase;

final class FailoverPolicyTest extends TestCase
{
    public function test_two_failure_switch_invariant_triggers_on_second_failure(): void
    {
        $policy = new DefaultDispatchPolicy();

        $providerId = $policy->chooseNextProvider(501, 3, [
            'providers' => [
                ['id' => 10],
                ['id' => 20],
            ],
            'last_provider_id' => 10,
            'consecutive_failures_for_last_provider' => 2,
        ]);

        self::assertSame(20, $providerId);
    }

    public function test_rotation_invariant_for_three_providers_after_failover_threshold(): void
    {
        $policy = new DefaultDispatchPolicy();

        $providerId = $policy->chooseNextProvider(502, 4, [
            'providers' => [
                ['id' => 10],
                ['id' => 20],
                ['id' => 30],
            ],
            'last_provider_id' => 20,
            'consecutive_failures_for_last_provider' => 2,
        ]);

        self::assertSame(30, $providerId);
    }
}
