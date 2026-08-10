<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Dispatch;

use OneSMTP\Dispatch\RoutingRuleSimulator;
use OneSMTP\Product\FeatureGate;
use PHPUnit\Framework\TestCase;

final class RoutingRuleSimulatorTest extends TestCase
{
    public function test_candidate_simulation_matches_without_returning_sample_values(): void
    {
        $simulator = new RoutingRuleSimulator(new FeatureGate([
            FeatureGate::SMART_ROUTING => true,
        ], true));

        $result = $simulator->simulate(
            [
                [
                    'provider_id' => 22,
                    'priority' => 10,
                    'conditions' => [
                        [
                            'field' => 'content',
                            'operator' => 'contains',
                            'value' => 'invoice fixture',
                        ],
                    ],
                ],
            ],
            [
                'sender' => 'billing@example.test',
                'recipient' => 'owner@example.test',
                'subject' => 'Synthetic invoice',
                'content' => 'invoice fixture body',
                'source_type' => 'plugin',
                'source_slug' => 'checkout',
            ],
            [
                [
                    'id' => 22,
                    'name' => 'Fixture SMTP',
                    'is_active' => 1,
                    'circuit_state' => 'closed',
                ],
            ],
            true
        );

        self::assertSame('matched', $result['status']);
        self::assertSame(22, $result['provider_id']);
        self::assertSame(1, $result['rule_id']);
        self::assertSame('Fixture SMTP', $result['provider_name']);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only assertion over an in-memory result.
        self::assertStringNotContainsString('invoice fixture body', serialize($result));
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only assertion over an in-memory result.
        self::assertStringNotContainsString('billing@example.test', serialize($result));
    }

    public function test_saved_simulation_reports_inactive_and_open_circuit_effects_without_matching(): void
    {
        $simulator = new RoutingRuleSimulator(new FeatureGate([
            FeatureGate::SMART_ROUTING => true,
        ], true));

        $result = $simulator->simulate(
            [
                [
                    'provider_id' => 11,
                    'conditions' => ['subject' => 'Synthetic subject'],
                ],
                [
                    'provider_id' => 22,
                    'conditions' => ['subject' => 'Synthetic subject'],
                ],
            ],
            ['subject' => 'Synthetic subject'],
            [
                [
                    'id' => 11,
                    'name' => 'Inactive SMTP',
                    'is_active' => 0,
                    'circuit_state' => 'closed',
                ],
                [
                    'id' => 22,
                    'name' => 'Open SMTP',
                    'is_active' => 1,
                    'circuit_state' => 'open',
                    'circuit_until' => '2999-01-01 00:00:00',
                ],
            ]
        );

        self::assertSame('no_eligible_provider', $result['status']);
        self::assertSame([], $result['eligible_provider_ids']);
        self::assertSame(
            [
                [
					'provider_id' => 11,
					'provider_name' => 'Inactive SMTP',
					'state' => 'inactive',
				],
                [
					'provider_id' => 22,
					'provider_name' => 'Open SMTP',
					'state' => 'circuit_open',
				],
            ],
            $result['provider_effects']
        );
    }

    public function test_empty_and_long_sample_states_are_safe_and_bounded(): void
    {
        $simulator = new RoutingRuleSimulator(new FeatureGate([
            FeatureGate::SMART_ROUTING => true,
        ], true));
        $rule = [
            'provider_id' => 22,
            'conditions' => [
                [
					'field' => 'content',
					'operator' => 'contains',
					'value' => 'needle',
				],
            ],
        ];

        self::assertSame(
            'candidate_empty',
            $simulator->simulate([], ['subject' => 'Synthetic subject'], [], true)['status']
        );
        self::assertSame('sample_empty', $simulator->simulate([$rule], [], [], true)['status']);

        self::assertSame(
            'candidate_invalid',
            $simulator->simulate(
                [
                    [
                        'provider_id' => 0,
                        'conditions' => ['subject' => 'Synthetic subject'],
                    ],
                ],
                ['subject' => 'Synthetic subject'],
                [],
                true
            )['status']
        );

        $result = $simulator->simulate(
            [$rule],
            ['content' => str_repeat('x', 4097) . 'needle'],
            [
				[
					'id' => 22,
					'name' => 'Fixture SMTP',
					'is_active' => 1,
				],
			],
            true
        );

        self::assertSame('no_match', $result['status']);
        self::assertSame(['content'], $result['truncated_fields']);
    }

    public function test_free_installation_denies_simulation(): void
    {
        $result = (new RoutingRuleSimulator())->simulate(
            [],
            ['subject' => 'Synthetic subject'],
            [],
            false
        );

        self::assertSame('pro_required', $result['status']);
    }
}
