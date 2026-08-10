<?php

declare(strict_types=1);

namespace OneSMTP\Quota;

use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\AttemptRepository;

final class ProviderQuotaManager
{
    private const GROUP = 'onesmtp';
    private const LOCK_KEY = 'provider_quota_send_lock';
    private const LOCK_TTL = 60;
    private const LOCK_RETRY_AFTER = 5;
    private const RESERVATION_PREFIX = 'provider_quota_reservation_';
    private const RESERVATION_TTL = 120;

    /** @var callable():int */
    private $clock;

    public function __construct(
        private AttemptRepository $attempts,
        private ?FeatureGate $featureGate = null,
        ?callable $clock = null
    ) {
        $this->featureGate = $featureGate ?? new FeatureGate();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @param array<int,array<string,mixed>> $providers */
    public function acquireLock(array $providers): bool
    {
        if ( ! $this->hasConfiguredLimits($providers)) {
            return true;
        }

        if (function_exists('wp_cache_add') && wp_using_ext_object_cache()) {
            return (bool) wp_cache_add(self::LOCK_KEY, 1, self::GROUP, self::LOCK_TTL);
        }

        if (get_transient(self::LOCK_KEY) !== false) {
            return false;
        }

        return set_transient(self::LOCK_KEY, 1, self::LOCK_TTL);
    }

    public function releaseLock(): void
    {
        if (function_exists('wp_cache_delete') && wp_using_ext_object_cache()) {
            wp_cache_delete(self::LOCK_KEY, self::GROUP);
        }

        delete_transient(self::LOCK_KEY);
    }

    public function getLockRetryAfter(): int
    {
        return self::LOCK_RETRY_AFTER;
    }

    public function reserveProvider(int $providerId): void
    {
        if ($providerId <= 0) {
            return;
        }

        $key = self::RESERVATION_PREFIX . $providerId;
        if (function_exists('wp_cache_add') && wp_using_ext_object_cache()) {
            if (wp_cache_add($key, 1, self::GROUP, self::RESERVATION_TTL)) {
                return;
            }

            if (function_exists('wp_cache_incr')) {
                wp_cache_incr($key, 1, self::GROUP);
            }

            return;
        }

        $current = get_transient($key);
        set_transient($key, max(0, (int) $current) + 1, self::RESERVATION_TTL);
    }

    public function releaseProviderReservation(int $providerId): void
    {
        if ($providerId <= 0) {
            return;
        }

        $key = self::RESERVATION_PREFIX . $providerId;
        if (function_exists('wp_cache_decr') && wp_using_ext_object_cache()) {
            $current = (int) wp_cache_decr($key, 1, self::GROUP);
            if ($current <= 0) {
                wp_cache_delete($key, self::GROUP);
            }

            return;
        }

        $current = (int) get_transient($key);
        if ($current <= 1) {
            delete_transient($key);

            return;
        }

        set_transient($key, $current - 1, self::RESERVATION_TTL);
    }

    public function getReservationCount(int $providerId): int
    {
        if ($providerId <= 0) {
            return 0;
        }

        $key = self::RESERVATION_PREFIX . $providerId;
        if (function_exists('wp_cache_get') && wp_using_ext_object_cache()) {
            return max(0, (int) wp_cache_get($key, self::GROUP));
        }

        return max(0, (int) get_transient($key));
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
