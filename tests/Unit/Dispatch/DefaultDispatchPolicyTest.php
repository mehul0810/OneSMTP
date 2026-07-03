<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Dispatch;

use OneSMTP\Dispatch\DefaultDispatchPolicy;
use PHPUnit\Framework\TestCase;

final class DefaultDispatchPolicyTest extends TestCase
{
    public function test_selects_first_provider_for_first_attempt(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertSame(11, $policy->chooseNextProvider(10, 1, [
            'providers' => [
                ['id' => 11],
                ['id' => 22],
            ],
        ]));
    }

    public function test_keeps_same_provider_before_two_consecutive_failures(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertSame(22, $policy->chooseNextProvider(10, 2, [
            'providers' => [
                ['id' => 11],
                ['id' => 22],
            ],
            'last_provider_id' => 22,
            'consecutive_failures_for_last_provider' => 1,
        ]));
    }

    public function test_switches_provider_after_two_consecutive_failures(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertSame(22, $policy->chooseNextProvider(10, 3, [
            'providers' => [
                ['id' => 11],
                ['id' => 22],
            ],
            'last_provider_id' => 11,
            'consecutive_failures_for_last_provider' => 2,
        ]));
    }

    public function test_rotates_in_order_when_more_than_two_providers(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertSame(303, $policy->chooseNextProvider(77, 4, [
            'providers' => [
                ['id' => 101],
                ['id' => 202],
                ['id' => 303],
            ],
            'last_provider_id' => 202,
            'consecutive_failures_for_last_provider' => 2,
        ]));

        self::assertSame(101, $policy->chooseNextProvider(77, 5, [
            'providers' => [
                ['id' => 101],
                ['id' => 202],
                ['id' => 303],
            ],
            'last_provider_id' => 303,
            'consecutive_failures_for_last_provider' => 2,
        ]));
    }

    public function test_hard_stops_when_attempt_number_exceeds_six(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertNull($policy->chooseNextProvider(10, 7, [
            'providers' => [
                ['id' => 11],
                ['id' => 22],
            ],
        ]));
    }

    public function test_manual_resend_provider_override_uses_forced_provider_when_present(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertSame(303, $policy->chooseNextProvider(88, 2, [
            'providers' => [
                ['id' => 101],
                ['id' => 202],
                ['id' => 303],
            ],
            'last_provider_id' => 101,
            'consecutive_failures_for_last_provider' => 1,
            'forced_provider_id' => 303,
        ]));
    }

    public function test_routing_rule_match_selects_provider_before_default_dispatch(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertSame(202, $policy->chooseNextProvider(77, 1, [
            'providers' => [
                ['id' => 101, 'priority' => 1, 'weight' => 1, 'is_active' => 1],
                ['id' => 202, 'priority' => 2, 'weight' => 1, 'is_active' => 1],
            ],
            'routing_rules' => [
                [
                    'provider_id' => 202,
                    'conditions' => ['source_type' => 'commerce'],
                ],
            ],
            'routing_context' => [
                'source_type' => 'commerce',
            ],
        ]));
    }

    public function test_routing_rule_no_match_keeps_existing_default_dispatch(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertSame(101, $policy->chooseNextProvider(0, 1, [
            'providers' => [
                ['id' => 101, 'priority' => 1, 'weight' => 1, 'is_active' => 1],
                ['id' => 202, 'priority' => 2, 'weight' => 1, 'is_active' => 1],
            ],
            'routing_rules' => [
                [
                    'provider_id' => 202,
                    'conditions' => ['source_type' => 'membership'],
                ],
            ],
            'routing_context' => [
                'source_type' => 'commerce',
            ],
        ]));
    }

    public function test_initial_selection_observes_provider_weight(): void
    {
        $policy = new DefaultDispatchPolicy();

        $providers = [
            ['id' => 11, 'priority' => 1, 'weight' => 1, 'is_active' => 1],
            ['id' => 22, 'priority' => 2, 'weight' => 3, 'is_active' => 1],
        ];

        self::assertSame(22, $policy->chooseNextProvider(1, 1, ['providers' => $providers]));
        self::assertSame(22, $policy->chooseNextProvider(2, 1, ['providers' => $providers]));
    }

    public function test_open_provider_circuit_is_skipped_until_closed_or_expired(): void
    {
        $policy = new DefaultDispatchPolicy();

        self::assertSame(22, $policy->chooseNextProvider(10, 1, [
            'providers' => [
                [
                    'id' => 11,
                    'priority' => 1,
                    'weight' => 3,
                    'is_active' => 1,
                    'circuit_state' => 'open',
                    'circuit_until' => gmdate('Y-m-d H:i:s', time() + 300),
                ],
                ['id' => 22, 'priority' => 2, 'weight' => 1, 'is_active' => 1],
            ],
        ]));

        self::assertSame(11, $policy->chooseNextProvider(0, 1, [
            'providers' => [
                [
                    'id' => 11,
                    'priority' => 1,
                    'weight' => 3,
                    'is_active' => 1,
                    'circuit_state' => 'open',
                    'circuit_until' => gmdate('Y-m-d H:i:s', time() - 300),
                ],
                ['id' => 22, 'priority' => 2, 'weight' => 1, 'is_active' => 1],
            ],
        ]));
    }
}
