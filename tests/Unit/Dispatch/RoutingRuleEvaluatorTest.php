<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Dispatch;

use OneSMTP\Dispatch\RoutingRuleEvaluator;
use OneSMTP\Product\FeatureGate;
use PHPUnit\Framework\TestCase;

final class RoutingRuleEvaluatorTest extends TestCase
{
    public function test_first_matching_rule_wins_after_deterministic_priority_ordering(): void
    {
        $evaluator = $this->enabledEvaluator();

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
        $evaluator = $this->enabledEvaluator();

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
        $evaluator = $this->enabledEvaluator();

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
        $evaluator = $this->enabledEvaluator();

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
        $evaluator = $this->enabledEvaluator();

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

    public function test_sender_recipient_subject_content_and_source_conditions_match_in_one_rule(): void
    {
        $evaluator = $this->enabledEvaluator();

        self::assertSame(22, $evaluator->evaluate(
            [
                [
                    'provider_id' => 22,
                    'conditions' => [
                        [
							'field' => 'sender',
							'operator' => 'equals',
							'value' => 'billing@example.test',
						],
                        [
							'field' => 'recipient',
							'operator' => 'contains',
							'value' => '@customer.test',
						],
                        [
							'field' => 'subject',
							'operator' => 'starts_with',
							'value' => '[Invoice]',
						],
                        [
							'field' => 'content',
							'operator' => 'contains',
							'value' => 'payment due',
						],
                        [
							'field' => 'source_slug',
							'operator' => 'equals',
							'value' => 'checkout',
						],
                    ],
                ],
            ],
            [
                'sender' => ['billing@example.test'],
                'recipient' => ['person@customer.test', 'copy@example.test'],
                'subject' => '[Invoice] April',
                'content' => 'Your payment due date is April 30.',
                'source_slug' => 'checkout',
            ],
            $this->providers()
        ));
    }

    public function test_matching_is_bounded_and_regex_like_operators_fail_closed(): void
    {
        $evaluator = $this->enabledEvaluator();
        $longContent = str_repeat('x', 4096) . 'needle';

        self::assertNull($evaluator->evaluate(
            [
                [
                    'provider_id' => 22,
                    'conditions' => [
                        [
							'field' => 'content',
							'operator' => 'contains',
							'value' => 'needle',
						],
                    ],
                ],
                [
                    'provider_id' => 33,
                    'conditions' => [
                        [
							'field' => 'content',
							'operator' => 'regex',
							'value' => '.*',
						],
                    ],
                ],
            ],
            ['content' => $longContent],
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

    private function enabledEvaluator(): RoutingRuleEvaluator
    {
        return new RoutingRuleEvaluator(new FeatureGate([FeatureGate::SMART_ROUTING => true], true));
    }
}
