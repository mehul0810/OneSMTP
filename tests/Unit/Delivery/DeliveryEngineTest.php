<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Delivery;

use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class DeliveryEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_actions'] = [];
        $GLOBALS['onesmtp_test_object_cache'] = [];
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_repeated_provider_failures_switch_to_next_provider_and_log_lineage(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            ['id' => 100, 'adapter_type' => 'missing', 'is_active' => 1, 'priority' => 1, 'weight' => 1],
            ['id' => 200, 'adapter_type' => 'missing', 'is_active' => 1, 'priority' => 2, 'weight' => 1],
        ];
        $GLOBALS['wpdb']->providerRowsById[200] = [
            'id' => 200,
            'adapter_type' => 'missing',
            'is_active' => 1,
            'priority' => 2,
            'weight' => 1,
            'config_json' => '{}',
        ];
        $GLOBALS['wpdb']->attemptHistoryByMessage[501] = [
            ['provider_id' => 100, 'result' => 'fail'],
            ['provider_id' => 100, 'result' => 'fail'],
        ];

        $engine = $this->buildEngine();
        $outcome = $engine->deliver(501, 3, ['to' => ['qa@example.com'], 'subject' => 'failover']);

        self::assertFalse($outcome->isSuccess());
        self::assertSame(200, $outcome->getProviderId());

        $event = $this->findEventInsert('provider_failover');
        self::assertNotNull($event);
        self::assertSame(501, $event['data']['message_id']);
        self::assertSame(200, $event['data']['provider_id']);

        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame(3, $context['attempt'] ?? null);
        self::assertSame(100, $context['from_provider_id'] ?? null);
        self::assertSame(200, $context['to_provider_id'] ?? null);
    }

    public function test_exhausted_provider_pool_returns_terminal_no_provider_outcome(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 100,
                'adapter_type' => 'missing',
                'is_active' => 1,
                'priority' => 1,
                'weight' => 1,
                'circuit_state' => 'open',
                'circuit_until' => gmdate('Y-m-d H:i:s', time() + 300),
            ],
        ];

        $engine = $this->buildEngine();
        $outcome = $engine->deliver(502, 2, ['to' => ['qa@example.com'], 'subject' => 'exhausted']);

        self::assertFalse($outcome->isSuccess());
        self::assertSame(0, $outcome->getProviderId());
        self::assertSame('no_provider', $outcome->getCode());

        $event = $this->findEventInsert('terminal_failure');
        self::assertNotNull($event);
        self::assertSame(502, $event['data']['message_id']);

        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame('provider_pool_exhausted', $context['reason'] ?? null);
    }

    private function buildEngine(): DeliveryEngine
    {
        return new DeliveryEngine(
            new ProviderRepository(),
            new AttemptRepository(),
            new DefaultDispatchPolicy(),
            null,
            new EventRepository()
        );
    }

    private function findEventInsert(string $eventType): ?array
    {
        foreach ($GLOBALS['wpdb']->inserts as $insert) {
            if (
                str_ends_with($insert['table'], 'onesmtp_events')
                && (($insert['data']['event_type'] ?? '') === $eventType)
            ) {
                return $insert;
            }
        }

        return null;
    }
}
