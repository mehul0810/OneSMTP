<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Integration\Dispatch;

use OneSMTP\Dispatch\RoutingRuleSimulator;
use OneSMTP\Dispatch\RoutingRulesRepository;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class RoutingRuleSimulationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['onesmtp_test_options'] = [
            'onesmtp_routing_rules' => [
                'value' => [
                    [
                        'id' => 7,
                        'provider_id' => 5,
                        'priority' => 10,
                        'enabled' => true,
                        'conditions' => [
                            [
								'field' => 'source_slug',
								'operator' => 'equals',
								'value' => 'checkout',
							],
                        ],
                    ],
                ],
                'autoload' => false,
            ],
        ];
        $GLOBALS['onesmtp_test_fired_actions'] = [];
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 5,
                'name' => 'Fixture SMTP',
                'is_active' => 1,
                'circuit_state' => 'closed',
            ],
        ];
    }

    public function test_saved_rules_simulate_through_repository_without_delivery_side_effects(): void
    {
        $rules = (new RoutingRulesRepository())->get();
        $providers = (new ProviderRepository())->getAll();
        $result = (new RoutingRuleSimulator(new FeatureGate([
            FeatureGate::SMART_ROUTING => true,
        ], true)))->simulate(
            $rules,
            [
                'subject' => 'Synthetic checkout message',
                'content' => 'Synthetic body',
                'source_type' => 'plugin',
                'source_slug' => 'checkout',
            ],
            $providers
        );

        self::assertSame('matched', $result['status']);
        self::assertSame(7, $result['rule_id']);
        self::assertSame(5, $result['provider_id']);
        self::assertSame([], $GLOBALS['wpdb']->inserts);
        self::assertSame([], $GLOBALS['wpdb']->updates);
        self::assertSame([], $GLOBALS['wpdb']->deletions);
        self::assertSame([], $GLOBALS['onesmtp_test_fired_actions']);
        self::assertArrayHasKey('onesmtp_routing_rules', $GLOBALS['onesmtp_test_options']);
    }
}
