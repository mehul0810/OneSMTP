<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Delivery;

use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\SendResult;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Quota\ProviderQuotaManager;
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

    public function test_quota_exhausted_provider_is_skipped_when_another_provider_is_available(): void
    {
        $now = 1700000000;
        $since = gmdate('Y-m-d H:i:s', $now - 60);
        $GLOBALS['wpdb']->activeProviders = [
            ['id' => 100, 'adapter_type' => 'missing', 'is_active' => 1, 'priority' => 1, 'weight' => 1, 'config_json' => wp_json_encode(['quota_per_minute' => 1])],
            ['id' => 200, 'adapter_type' => 'missing', 'is_active' => 1, 'priority' => 2, 'weight' => 1, 'config_json' => '{}'],
        ];
        $GLOBALS['wpdb']->providerRowsById[200] = $GLOBALS['wpdb']->activeProviders[1];
        $GLOBALS['wpdb']->providerAttemptWindowStatsByProviderSince['100|' . $since] = [
            'attempt_count' => 1,
            'oldest_created_at' => gmdate('Y-m-d H:i:s', $now - 30),
        ];

        $engine = $this->buildEngine(new FeatureGate([FeatureGate::PROVIDER_QUOTA_BUDGETS => true], true), $now);
        $outcome = $engine->deliver(505, 1, ['to' => ['qa@example.com'], 'subject' => 'quota skip']);

        self::assertFalse($outcome->isSuccess());
        self::assertFalse($outcome->isDeferred());
        self::assertSame(200, $outcome->getProviderId());
    }

    public function test_all_quota_exhausted_providers_return_typed_deferral_without_terminal_event(): void
    {
        $now = 1700000000;
        $since = gmdate('Y-m-d H:i:s', $now - 60);
        $GLOBALS['wpdb']->activeProviders = [
            ['id' => 100, 'adapter_type' => 'missing', 'is_active' => 1, 'priority' => 1, 'weight' => 1, 'config_json' => wp_json_encode(['quota_per_minute' => 1])],
        ];
        $GLOBALS['wpdb']->providerAttemptWindowStatsByProviderSince['100|' . $since] = [
            'attempt_count' => 1,
            'oldest_created_at' => gmdate('Y-m-d H:i:s', $now - 30),
        ];

        $engine = $this->buildEngine(new FeatureGate([FeatureGate::PROVIDER_QUOTA_BUDGETS => true], true), $now);
        $outcome = $engine->deliver(506, 2, ['to' => ['qa@example.com'], 'subject' => 'quota defer']);

        self::assertTrue($outcome->isDeferred());
        self::assertSame('provider_quota_deferred', $outcome->getCode());
        self::assertSame(30, $outcome->getRetryAfter());
        self::assertNull($this->findEventInsert('terminal_failure'));
    }

    public function test_forced_provider_override_rejects_ineligible_provider_without_fallback(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            ['id' => 100, 'adapter_type' => 'missing', 'is_active' => 1, 'priority' => 1, 'weight' => 1],
        ];
        $GLOBALS['wpdb']->providerRowsById[100] = [
            'id' => 100,
            'adapter_type' => 'missing',
            'is_active' => 1,
            'priority' => 1,
            'weight' => 1,
            'config_json' => '{}',
        ];

        $engine = $this->buildEngine();
        $outcome = $engine->deliver(503, 2, ['to' => ['qa@example.com'], 'subject' => 'forced'], 999);

        self::assertFalse($outcome->isSuccess());
        self::assertSame(999, $outcome->getProviderId());
        self::assertSame('ineligible_provider', $outcome->getCode());

        $event = $this->findEventInsert('manual_resend_rejected');
        self::assertNotNull($event);
        self::assertSame(503, $event['data']['message_id']);
        self::assertSame(999, $event['data']['provider_id']);
    }

    public function test_provider_call_latency_is_measured_on_the_delivery_outcome(): void
    {
        $provider = [
            'id' => 100,
            'adapter_type' => 'timed',
            'is_active' => 1,
            'priority' => 1,
            'weight' => 1,
            'config_json' => '{}',
        ];
        $GLOBALS['wpdb']->activeProviders = [$provider];
        $GLOBALS['wpdb']->providerRowsById[100] = $provider;

        $adapter = new class implements ProviderAdapterInterface {
            public function getSlug(): string
            {
                return 'timed';
            }

            public function send(array $message, ProviderConfig $config): SendResult
            {
                return new SendResult(true, 'sent', 'Accepted.');
            }

            public function testConnection(ProviderConfig $config): SendResult
            {
                return new SendResult(true, 'connected', 'Connected.');
            }
        };
        $ticks = [1_000_000_000, 1_125_000_000];
        $engine = new DeliveryEngine(
            new ProviderRepository(),
            new AttemptRepository(),
            new DefaultDispatchPolicy(),
            new ProviderDeliveryManager(new ProviderAdapterRegistry(['timed' => $adapter])),
            new EventRepository(),
            static function () use (&$ticks): int {
                return (int) array_shift($ticks);
            }
        );

        $outcome = $engine->deliver(504, 1, ['to' => ['qa@example.com'], 'subject' => 'Timed']);

        self::assertTrue($outcome->isSuccess());
        self::assertSame(125, $outcome->getLatencyMs());
    }

    private function buildEngine(?FeatureGate $featureGate = null, ?int $now = null): DeliveryEngine
    {
        $attempts = new AttemptRepository();
        $quota = $featureGate !== null
            ? new ProviderQuotaManager($attempts, $featureGate, static fn (): int => $now ?? time())
            : null;

        return new DeliveryEngine(
            new ProviderRepository(),
            $attempts,
            new DefaultDispatchPolicy(),
            null,
            new EventRepository(),
            null,
            $quota
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
