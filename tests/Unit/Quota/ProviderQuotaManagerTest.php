<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Quota;

use OneSMTP\Product\FeatureGate;
use OneSMTP\Quota\ProviderQuotaManager;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class ProviderQuotaManagerTest extends TestCase
{
    private const NOW = 1700000000;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_transients'] = [];
    }

    public function test_default_deny_gate_preserves_core_provider_selection(): void
    {
        $manager = $this->manager(new FeatureGate(), self::NOW);
        $providers = [$this->provider(10, ['quota_per_minute' => 1])];

        $selection = $manager->filterProviders($providers);

        self::assertSame($providers, $selection['providers']);
        self::assertNull($selection['deferred']);
    }

    public function test_exhausted_provider_is_skipped_and_exact_window_reset_is_returned(): void
    {
        $provider = $this->provider(10, ['quota_per_minute' => 2]);
        $since = gmdate('Y-m-d H:i:s', self::NOW - 60);
        $GLOBALS['wpdb']->providerAttemptWindowStatsByProviderSince[ '10|' . $since ] = [
            'attempt_count' => 2,
            'oldest_created_at' => gmdate('Y-m-d H:i:s', self::NOW),
        ];
        $manager = $this->manager(new FeatureGate([FeatureGate::PROVIDER_QUOTA_BUDGETS => true], true), self::NOW);

        $decision = $manager->evaluateProvider($provider);
        $selection = $manager->filterProviders([$provider, $this->provider(20, [])]);

        self::assertFalse($decision->canSend());
        self::assertSame(60, $decision->getRetryAfter());
        self::assertSame(self::NOW + 60, $decision->getNextCapacityAt());
        self::assertSame('minute', $decision->getWindow());
        self::assertSame([20], array_column($selection['providers'], 'id'));
        self::assertNull($selection['deferred']);
    }

    public function test_all_exhausted_providers_defer_at_earliest_capacity(): void
    {
        $providers = [
            $this->provider(10, ['quota_per_minute' => 1]),
            $this->provider(20, ['quota_per_hour' => 1]),
        ];
        $minuteSince = gmdate('Y-m-d H:i:s', self::NOW - 60);
        $hourSince = gmdate('Y-m-d H:i:s', self::NOW - 3600);
        $GLOBALS['wpdb']->providerAttemptWindowStatsByProviderSince[ '10|' . $minuteSince ] = [
            'attempt_count' => 1,
            'oldest_created_at' => gmdate('Y-m-d H:i:s', self::NOW - 20),
        ];
        $GLOBALS['wpdb']->providerAttemptWindowStatsByProviderSince[ '20|' . $hourSince ] = [
            'attempt_count' => 1,
            'oldest_created_at' => gmdate('Y-m-d H:i:s', self::NOW - 300),
        ];
        $manager = $this->manager(new FeatureGate([FeatureGate::PROVIDER_QUOTA_BUDGETS => true], true), self::NOW);

        $selection = $manager->filterProviders($providers);

        self::assertSame([], $selection['providers']);
        self::assertNotNull($selection['deferred']);
        self::assertSame(self::NOW + 40, $selection['deferred']->getNextCapacityAt());
        self::assertSame(40, $selection['deferred']->getRetryAfter());
        self::assertSame('provider_pool', $selection['deferred']->getWindow());
    }

    public function test_inflight_send_reservation_counts_until_the_recorded_attempt_is_persisted(): void
    {
        $provider = $this->provider(10, ['quota_per_minute' => 1]);
        $manager = $this->manager(new FeatureGate([FeatureGate::PROVIDER_QUOTA_BUDGETS => true], true), self::NOW);

        $reservationToken = $manager->reserveProvider(10);
        self::assertNotNull($reservationToken);
        $decision = $manager->evaluateProvider($provider);
        self::assertFalse($decision->canSend());
        self::assertSame(1, $manager->getReservationCount(10));

        $manager->releaseProviderReservation(10, $reservationToken);
        self::assertSame(0, $manager->getReservationCount(10));
        self::assertTrue($manager->evaluateProvider($provider)->canSend());
    }

    private function manager(FeatureGate $gate, int $now): ProviderQuotaManager
    {
        return new ProviderQuotaManager(new AttemptRepository(), $gate, static fn (): int => $now);
    }

    /** @param array<string,int> $config */
    private function provider(int $id, array $config): array
    {
        return [
            'id' => $id,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config' => $config,
        ];
    }
}
