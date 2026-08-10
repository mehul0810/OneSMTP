<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Dispatch;

use InvalidArgumentException;
use OneSMTP\Dispatch\RoutingRuleNormalizer;
use PHPUnit\Framework\TestCase;

final class RoutingRuleNormalizerTest extends TestCase
{
    public function test_normalizes_and_assigns_stable_rule_ids(): void
    {
        $rules = (new RoutingRuleNormalizer())->normalizeRules([
            [
                'provider_id' => 5,
                'priority' => 20,
                'conditions' => ['source_type' => 'plugin'],
            ],
            [
                'id' => 9,
                'provider_id' => 6,
                'priority' => 10,
                'conditions' => [
                    [
						'field' => 'subject',
						'operator' => 'contains',
						'value' => 'Invoice',
					],
                ],
            ],
        ]);

        self::assertSame(1, $rules[0]['id']);
        self::assertSame(9, $rules[1]['id']);
        self::assertSame('equals', $rules[0]['conditions'][0]['operator']);
    }

    public function test_rejects_unbounded_or_unsafe_conditions_for_admin_saves(): void
    {
        $normalizer = new RoutingRuleNormalizer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('500 characters');
        $normalizer->normalizeRule([
            'provider_id' => 5,
            'conditions' => [
                [
                    'field' => 'content',
                    'operator' => 'contains',
                    'value' => str_repeat('x', RoutingRuleNormalizer::MAX_VALUE_LENGTH + 1),
                ],
            ],
        ], true);
    }

    public function test_runtime_normalization_skips_unsupported_fields_without_throwing(): void
    {
        self::assertSame([], (new RoutingRuleNormalizer())->normalizeConditions([
            [
				'field' => 'raw_headers',
				'operator' => 'exists',
			],
        ]));
    }
}
