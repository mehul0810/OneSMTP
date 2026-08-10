<?php

declare(strict_types=1);

namespace OneSMTP\Dispatch;

use OneSMTP\Product\FeatureGate;

final class DefaultDispatchPolicy implements DispatchPolicyInterface
{
    private const MAX_ATTEMPTS = 6;

    private RoutingRuleEvaluator $routingRules;
    private RoutingRulesRepository $rulesRepository;
    private RoutingContextBuilder $contextBuilder;

    public function __construct(
        ?RoutingRuleEvaluator $routingRules = null,
        ?FeatureGate $featureGate = null,
        ?RoutingRulesRepository $rulesRepository = null,
        ?RoutingContextBuilder $contextBuilder = null
    ) {
        $this->routingRules = $routingRules ?? new RoutingRuleEvaluator($featureGate);
        $this->rulesRepository = $rulesRepository ?? new RoutingRulesRepository();
        $this->contextBuilder = $contextBuilder ?? new RoutingContextBuilder();
    }

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

        $routingRules = array_key_exists('routing_rules', $context) && is_array($context['routing_rules'])
            ? $context['routing_rules']
            : $this->rulesRepository->get();
        $routingContext = array_key_exists('routing_context', $context) && is_array($context['routing_context'])
            ? $context['routing_context']
            : $this->contextBuilder->fromPayload(
                is_array($context['payload'] ?? null) ? $context['payload'] : []
            );

        $routingProviderId = $this->routingRules->evaluate(
            $routingRules,
            $routingContext,
            $providers
        );

        if ($routingProviderId !== null && $this->providerExists($weightedPool, $routingProviderId)) {
            return $routingProviderId;
        }

        $lastProviderId = isset($context['last_provider_id']) ? (int) $context['last_provider_id'] : 0;
        $consecutive    = isset($context['consecutive_failures_for_last_provider'])
            ? (int) $context['consecutive_failures_for_last_provider']
            : 0;

        if ($attemptNumber <= 1 || $lastProviderId <= 0) {
            $startIndex = $this->initialPoolIndex($messageId, count($weightedPool));

            return (int) $weightedPool[ $startIndex ]['id'];
        }

        // Normal retries keep the current provider for one additional attempt so
        // transient provider blips do not rotate the pool unnecessarily. The
        // delivery pipeline can opt into immediate failover when a provider has
        // already failed and another healthy provider is available.
        $failureThreshold = ! empty($context['failover_on_first_failure']) ? 1 : 2;
        if ($consecutive >= $failureThreshold) {
            return $this->nextProviderInOrder($weightedPool, $lastProviderId);
        }

        return $lastProviderId;
    }

    private function normalizeProviders(array $providers): array
    {
        $normalized = [];

        foreach ($providers as $provider) {
            if ( ! is_array($provider)) {
                continue;
            }

            if (array_key_exists('is_active', $provider) && (int) $provider['is_active'] !== 1) {
                continue;
            }

            $state = (string) ($provider['circuit_state'] ?? 'closed');
            if ($state === 'open' && $this->isCircuitStillOpen($provider)) {
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
            if ( (int) $provider['id'] !== $lastProviderId) {
                continue;
            }

            $nextIndex = ($index + 1) % $count;

            return (int) $providers[ $nextIndex ]['id'];
        }

        return (int) $providers[0]['id'];
    }

    private function providerExists(array $providers, int $providerId): bool
    {
        foreach ($providers as $provider) {
            if ( (int) ($provider['id'] ?? 0) === $providerId) {
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

    private function isCircuitStillOpen(array $provider): bool
    {
        $until = isset($provider['circuit_until']) ? (string) $provider['circuit_until'] : '';
        if ($until === '') {
            return true;
        }

        $untilTs = strtotime($until);
        if ($untilTs === false) {
            return true;
        }

        return $untilTs > time();
    }
}
