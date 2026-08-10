<?php

declare(strict_types=1);

namespace OneSMTP\Quota;

use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\AttemptRepository;

final class ProviderQuotaManager
{
    private const LOCK_KEY = 'provider_quota_send_lock';
    private const LOCK_TTL = 60;
    private const LOCK_RETRY_AFTER = 5;
    private const RESERVATION_TTL = 120;

    /** @var callable():int */
    private $clock;
    private ProviderQuotaLeaseStore $leases;
    private ?string $lockToken = null;

    public function __construct(
        private AttemptRepository $attempts,
        private ?FeatureGate $featureGate = null,
        ?callable $clock = null,
        ?ProviderQuotaLeaseStore $leases = null
    ) {
        $this->featureGate = $featureGate ?? new FeatureGate();
        $this->clock = $clock ?? static fn (): int => time();
        $this->leases = $leases ?? new ProviderQuotaLeaseStore($this->clock);
    }

    /** @param array<int,array<string,mixed>> $providers */
    public function acquireLock(array $providers): bool
    {
        if ( ! $this->hasConfiguredLimits($providers)) {
            return true;
        }

        $this->lockToken = $this->leases->acquireLock(self::LOCK_KEY, self::LOCK_TTL);

        return $this->lockToken !== null;
    }

    public function releaseLock(): void
    {
        if ($this->lockToken !== null) {
            $this->leases->releaseLock(self::LOCK_KEY, $this->lockToken);
            $this->lockToken = null;
        }
    }

    public function getLockRetryAfter(): int
    {
        return self::LOCK_RETRY_AFTER;
    }

    public function reserveProvider(int $providerId): ?string
    {
        if ($providerId <= 0) {
            return null;
        }

        return $this->leases->reserveProvider($providerId, self::RESERVATION_TTL);
    }

    public function releaseProviderReservation(int $providerId, ?string $reservationToken = null): void
    {
        if ($providerId <= 0 || $reservationToken === null || $reservationToken === '') {
            return;
        }

        $this->leases->releaseProviderReservation($providerId, $reservationToken);
    }

    public function getReservationCount(int $providerId): int
    {
        if ($providerId <= 0) {
            return 0;
        }

        return $this->leases->countReservations($providerId);
    }

    /**
     * @param array<int,array<string,mixed>> $providers
     * @return array{providers:array<int,array<string,mixed>>,deferred:?ProviderQuotaDecision}
     */
    public function filterProviders(array $providers): array
    {
        if ( ! $this->hasConfiguredLimits($providers)) {
            return [
				'providers' => $providers,
				'deferred' => null,
			];
        }

        $available = [];
        $blocked = [];
        foreach ($providers as $provider) {
            $decision = $this->evaluateProvider($provider);
            if ($decision->canSend()) {
                $available[] = $provider;
                continue;
            }

            $blocked[] = $decision;
        }

        if ($available !== [] || $blocked === []) {
            return [
				'providers' => $available,
				'deferred' => null,
			];
        }

        $now = $this->now();
        $earliest = null;
        foreach ($blocked as $decision) {
            $nextCapacityAt = $decision->getNextCapacityAt();
            if ($nextCapacityAt === null || ($earliest !== null && $nextCapacityAt >= $earliest)) {
                continue;
            }

            $earliest = $nextCapacityAt;
        }

        $earliest = $earliest ?? ($now + self::LOCK_RETRY_AFTER);

        return [
            'providers' => [],
            'deferred' => ProviderQuotaDecision::deferred(
                max(1, $earliest - $now),
                $earliest,
                0,
                'provider_pool'
            ),
        ];
    }

    /** @param array<string,mixed> $provider */
    public function evaluateProvider(array $provider): ProviderQuotaDecision
    {
        $providerId = (int) ($provider['id'] ?? 0);
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $settings = ProviderQuotaSettings::fromProviderConfig($config);

        if ( ! $this->featureGate->isEnabled(FeatureGate::PROVIDER_QUOTA_BUDGETS) || ! $settings->hasAnyLimit() || $providerId <= 0) {
            return ProviderQuotaDecision::allowed();
        }

        $now = $this->now();
        $blockedWindows = [];
        foreach ($settings->configuredWindows() as $window) {
            $since = gmdate('Y-m-d H:i:s', $now - $window['seconds']);
            $stats = $this->attempts->getProviderAttemptWindowStats($providerId, $since);
            $used = (int) ($stats['attempt_count'] ?? 0) + $this->getReservationCount($providerId);
            if ($used < $window['limit']) {
                continue;
            }

            $oldest = isset($stats['oldest_created_at']) ? strtotime( (string) $stats['oldest_created_at']) : false;
            $resetAt = is_int($oldest) ? $oldest + $window['seconds'] : $now + $window['seconds'];
            $blockedWindows[] = [
                'reset_at' => max($now + 1, $resetAt),
                'window' => $window['name'],
                'limit' => $window['limit'],
                'used' => $used,
            ];
        }

        if ($blockedWindows === []) {
            return ProviderQuotaDecision::allowed();
        }

        usort(
            $blockedWindows,
            static fn (array $a, array $b): int => $b['reset_at'] <=> $a['reset_at']
        );
        $blocked = $blockedWindows[0];

        return ProviderQuotaDecision::deferred(
            max(1, $blocked['reset_at'] - $now),
            $blocked['reset_at'],
            $providerId,
            (string) $blocked['window'],
            (int) $blocked['limit'],
            (int) $blocked['used']
        );
    }

    /** @param array<int,array<string,mixed>> $providers */
    public function hasConfiguredLimits(array $providers): bool
    {
        if ( ! $this->featureGate->isEnabled(FeatureGate::PROVIDER_QUOTA_BUDGETS)) {
            return false;
        }

        foreach ($providers as $provider) {
            $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
            if (ProviderQuotaSettings::fromProviderConfig($config)->hasAnyLimit()) {
                return true;
            }
        }

        return false;
    }

    private function now(): int
    {
        return max(1, (int) call_user_func($this->clock));
    }
}
