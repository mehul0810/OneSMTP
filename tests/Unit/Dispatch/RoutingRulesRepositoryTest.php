<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Dispatch;

use OneSMTP\Dispatch\RoutingRulesRepository;
use PHPUnit\Framework\TestCase;

final class RoutingRulesRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['onesmtp_test_options'] = [];
    }

    public function test_stores_only_normalized_rule_definitions(): void
    {
        $repository = new RoutingRulesRepository();

        self::assertTrue($repository->add([
            'provider_id' => 5,
            'priority' => 10,
            'conditions' => [
                [
					'field' => 'content',
					'operator' => 'contains',
					'value' => 'fixture phrase',
				],
            ],
        ]));

        $stored = get_option('onesmtp_routing_rules', []);
        self::assertIsArray($stored);
        self::assertArrayNotHasKey('message', $stored[0] ?? []);
        self::assertSame('fixture phrase', $stored[0]['conditions'][0]['value'] ?? null);
    }

    public function test_delete_is_bounded_to_a_known_rule_id(): void
    {
        $repository = new RoutingRulesRepository();
        $repository->add([
            'provider_id' => 5,
            'conditions' => ['source' => 'checkout'],
        ]);

        self::assertFalse($repository->delete(99));
        self::assertTrue($repository->delete(1));
        self::assertSame([], $repository->get());
    }

    public function test_update_preserves_stable_rule_id_and_replaces_normalized_definition(): void
    {
        $repository = new RoutingRulesRepository();
        $repository->add([
            'provider_id' => 5,
            'priority' => 10,
            'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'old']],
        ]);

        self::assertTrue($repository->update(1, [
            'provider_id' => 6,
            'priority' => 2,
            'enabled' => true,
            'conditions' => [['field' => 'content', 'operator' => 'contains', 'value' => 'new']],
        ]));

        $updated = $repository->get();
        self::assertSame(1, $updated[0]['id'] ?? null);
        self::assertSame(6, $updated[0]['provider_id'] ?? null);
        self::assertSame('new', $updated[0]['conditions'][0]['value'] ?? null);
    }
}
