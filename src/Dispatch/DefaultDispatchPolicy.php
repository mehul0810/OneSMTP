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

        $providers = isset($context['providers']) && is_array($context['providers']) ? $context['providers'] : [];

        if ($providers === []) {
            return null;
        }

        $forcedProviderId = isset($context['forced_provider_id']) ? (int) $context['forced_provider_id'] : 0;
        if ($forcedProviderId > 0 && $this->providerExists($providers, $forcedProviderId)) {
            return $forcedProviderId;
        }

        $lastProviderId = isset($context['last_provider_id']) ? (int) $context['last_provider_id'] : 0;
        $consecutive    = isset($context['consecutive_failures_for_last_provider'])
            ? (int) $context['consecutive_failures_for_last_provider']
            : 0;

        if ($attemptNumber <= 1 || $lastProviderId <= 0) {
            return (int) $providers[0]['id'];
        }

        // Invariant: after 2 consecutive failures, switch away from the current provider.
        if ($consecutive >= 2) {
            return $this->nextProviderInOrder($providers, $lastProviderId);
        }

        return $lastProviderId;
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
}
