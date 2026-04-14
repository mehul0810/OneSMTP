<?php

declare(strict_types=1);

namespace OneSMTP\Dispatch;

final class DefaultDispatchPolicy implements DispatchPolicyInterface
{
    private const MAX_ATTEMPTS = 6;

    public function chooseNextProvider(int $messageId, int $attemptNumber, array $context): ?int
    {
        if ($attemptNumber > self::MAX_ATTEMPTS) {
            return null;
        }

        $providers = $this->normalizeProviders($context['providers'] ?? []);
        if ($providers === []) {
            return null;
        }

        $weightedPool = $this->buildWeightedPool($providers);
        if ($weightedPool === []) {
            return null;
        }

        $forcedProviderId = isset($context['forced_provider_id']) ? (int) $context['forced_provider_id'] : 0;
        if ($forcedProviderId > 0 && $this->providerExists($weightedPool, $forcedProviderId)) {
            return $forcedProviderId;
        }

        $lastProviderId = isset($context['last_provider_id']) ? (int) $context['last_provider_id'] : 0;
        $consecutive    = isset($context['consecutive_failures_for_last_provider'])
            ? (int) $context['consecutive_failures_for_last_provider']
            : 0;

        if ($attemptNumber <= 1 || $lastProviderId <= 0) {
            $startIndex = $this->initialPoolIndex($messageId, count($weightedPool));

            return (int) $weightedPool[$startIndex]['id'];
        }

        // Invariant: after 2 consecutive failures, switch away from the current provider.
        if ($consecutive >= 2) {
            return $this->nextProviderInOrder($weightedPool, $lastProviderId);
        }

        return $lastProviderId;
    }

    private function normalizeProviders(array $providers): array
    {
        $normalized = [];

        foreach ($providers as $provider) {
            if (! is_array($provider)) {
                continue;
            }

            if ((int) ($provider['is_active'] ?? 0) !== 1) {
                continue;
            }

            $state = (string) ($provider['circuit_state'] ?? 'closed');
            if ($state === 'open') {
                continue;
            }

            $normalized[] = [
                'id'       => (int) ($provider['id'] ?? 0),
                'priority' => (int) ($provider['priority'] ?? 100),
                'weight'   => max(1, (int) ($provider['weight'] ?? 1)),
            ];
        }

        usort(
            $normalized,
            static function (array $a, array $b): int {
                $priorityCmp = $a['priority'] <=> $b['priority'];
                if ($priorityCmp !== 0) {
                    return $priorityCmp;
                }

                return $a['id'] <=> $b['id'];
            }
        );

        return $normalized;
    }

    private function buildWeightedPool(array $providers): array
    {
        $weighted = [];

        foreach ($providers as $provider) {
            for ($i = 0; $i < $provider['weight']; $i++) {
                $weighted[] = $provider;
            }
        }

        return $weighted;
    }

    private function nextProviderInOrder(array $providers, int $lastProviderId): int
    {
        $count = count($providers);

        foreach ($providers as $index => $provider) {
            if ((int) $provider['id'] !== $lastProviderId) {
                continue;
            }

            $nextIndex = ($index + 1) % $count;

            return (int) $providers[$nextIndex]['id'];
        }

        return (int) $providers[0]['id'];
    }

    private function providerExists(array $providers, int $providerId): bool
    {
        foreach ($providers as $provider) {
            if ((int) ($provider['id'] ?? 0) === $providerId) {
                return true;
            }
        }

        return false;
    }

    private function initialPoolIndex(int $messageId, int $poolSize): int
    {
        if ($poolSize <= 1) {
            return 0;
        }

        return abs($messageId) % $poolSize;
    }
}
