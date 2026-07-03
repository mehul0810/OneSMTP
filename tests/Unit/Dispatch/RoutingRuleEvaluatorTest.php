<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Dispatch;

use OneSMTP\Dispatch\RoutingRuleEvaluator;
use PHPUnit\Framework\TestCase;

final class RoutingRuleEvaluatorTest extends TestCase
{
    public function test_first_matching_rule_wins_after_deterministic_priority_ordering(): void
    {
        $evaluator = new RoutingRuleEvaluator();

        self::assertSame(22, $evaluator->evaluate(
            [
                [
                    'provider_id' => 11,
                    'priority' => 20,
                    'conditions' => [
                        [
                            'field' => 'source_type',
                            'operator' => 'equals',
                            'value' => 'commerce',
                        ],
                    ],
                ],
                [
                    'provider_id' => 22,
                    'priority' => 10,
                    'conditions' => [
                        [
                            'field' => 'source_type',
                            'operator' => 'equals',
                            'value' => 'commerce',
                        ],
                    ],
                ],
            ],
            ['source_type' => 'commerce'],
            $this->providers()
        ));
    }

    public function test_same_priority_rules_keep_configured_order(): void
    {
        $evaluator = new RoutingRuleEvaluator();

        self::assertSame(11, $evaluator->evaluate(
            [
                [
                    'provider_id' => 11,
                    'priority' => 10,
                    'conditions' => ['source_type' => 'commerce'],
                ],
                [
                    'provider_id' => 22,
                    'priority' => 10,
                    'conditions' => ['source_type' => 'commerce'],
                ],
            ],
            ['source_type' => 'commerce'],
            $this->providers()
        ));
    }

    public function test_no_match_returns_null_for_default_dispatch_fallback(): void
    {
        $evaluator = new RoutingRuleEvaluator();

        self::assertNull($evaluator->evaluate(
            [
                [
                    'provider_id' => 22,
                    'conditions' => [
                        [
                            'field' => 'source_type',
                            'operator' => 'equals',
                            'value' => 'membership',
                        ],
                    ],
                ],
            ],
            ['source_type' => 'commerce'],
            $this->providers()
        ));
    }

    public function test_disabled_invalid_and_ineligible_rules_are_skipped_safely(): void
    {
        $evaluator = new RoutingRuleEvaluator();

        self::assertSame(33, $evaluator->evaluate(
            [
                [
                    'provider_id' => 11,
                    'enabled' => false,
                    'priority' => 1,
                    'conditions' => ['source_type' => 'commerce'],
                ],
                [
                    'provider_id' => 999,
                    'priority' => 2,
                    'conditions' => ['source_type' => 'commerce'],
                ],
                [
                    'provider_id' => 22,
                    'priority' => 3,
                    'conditions' => 'malformed',
                ],
                [
                    'provider_id' => 33,
                    'priority' => 4,
                    'conditions' => ['source_type' => 'commerce'],
                ],
            ],
            ['source_type' => 'commerce'],
            $this->providers()
        ));
    }

    public function test_privacy_sensitive_fields_are_not_eligible_for_matching(): void
    {
        $evaluator = new RoutingRuleEvaluator();

        self::assertNull($evaluator->evaluate(
            [
                [
                    'provider_id' => 22,
                    'conditions' => [
                        [
                            'field' => 'body',
                            'operator' => 'exists',
                        ],
                    ],
                ],
                [
                    'provider_id' => 33,
                    'conditions' => [
                        [
                            'field' => 'raw_headers',
                            'operator' => 'exists',
                        ],
                    ],
                ],
            ],
            [
                'source_type' => 'commerce',
                'body' => 'redacted',
                'raw_headers' => 'redacted',
            ],
            $this->providers()
        ));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function providers(): array
    {
        return [
            [
                'id' => 11,
                'priority' => 1,
                'weight' => 1,
                'is_active' => 1,
            ],
            [
                'id' => 22,
                'priority' => 2,
                'weight' => 1,
                'is_active' => 1,
            ],
            [
                'id' => 33,
                'priority' => 3,
                'weight' => 1,
                'is_active' => 1,
            ],
        ];
    }
}
