<?php

declare(strict_types=1);

namespace OneSMTP\Dispatch;

use OneSMTP\Product\FeatureGate;

final class RoutingRuleEvaluator
{
    private FeatureGate $featureGate;
    private RoutingRuleNormalizer $normalizer;

    public function __construct(?FeatureGate $featureGate = null, ?RoutingRuleNormalizer $normalizer = null)
    {
        $this->featureGate = $featureGate ?? new FeatureGate();
        $this->normalizer = $normalizer ?? new RoutingRuleNormalizer();
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed>            $context
     * @param array<int,array<string,mixed>> $providers
     */
    public function evaluate(array $rules, array $context, array $providers): ?int
    {
        if ( ! $this->featureGate->isEnabled(FeatureGate::SMART_ROUTING)) {
            return null;
        }

        $eligibleProviderIds = $this->eligibleProviderIds($providers);
        if ($rules === [] || $context === [] || $eligibleProviderIds === []) {
            return null;
        }

        foreach ($this->orderedRules($rules) as $rule) {
            if ( ! $this->isRuleEnabled($rule)) {
                continue;
            }

            $providerId = isset($rule['provider_id']) ? (int) $rule['provider_id'] : 0;
            if ($providerId <= 0 || ! in_array($providerId, $eligibleProviderIds, true)) {
                continue;
            }

            $conditions = $this->normalizer->normalizeConditions($rule['conditions'] ?? null);
            if ($conditions === [] || ! $this->conditionsMatch($conditions, $context)) {
                continue;
            }

            return $providerId;
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $providers
     *
     * @return array<int,int>
     */
    private function eligibleProviderIds(array $providers): array
    {
        $ids = [];

        foreach ($providers as $provider) {
            if ( ! is_array($provider)) {
                continue;
            }

            if (array_key_exists('is_active', $provider) && (int) $provider['is_active'] !== 1) {
                continue;
            }

            if ( (string) ($provider['circuit_state'] ?? 'closed') === 'open' && $this->isCircuitStillOpen($provider)) {
                continue;
            }

            $id = (int) ($provider['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     *
     * @return array<int,array<string,mixed>>
     */
    private function orderedRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $index => $rule) {
            if ( ! is_array($rule)) {
                continue;
            }

            $rule['_onesmtp_rule_index'] = (int) $index;
            $normalized[] = $rule;
        }

        usort(
            $normalized,
            static function (array $a, array $b): int {
                $priority = (int) ($a['priority'] ?? 100) <=> (int) ($b['priority'] ?? 100);
                if ($priority !== 0) {
                    return $priority;
                }

                return (int) $a['_onesmtp_rule_index'] <=> (int) $b['_onesmtp_rule_index'];
            }
        );

        foreach ($normalized as &$rule) {
            unset($rule['_onesmtp_rule_index']);
        }
        unset($rule);

        return $normalized;
    }

    /**
     * @param array<string,mixed> $rule
     */
    private function isRuleEnabled(array $rule): bool
    {
        if ( ! array_key_exists('enabled', $rule)) {
            return true;
        }

        $enabled = $rule['enabled'];

        return $enabled === true || $enabled === 1 || $enabled === '1';
    }

    /**
     * @param array<int,array{field:string,operator:string,value:mixed}> $conditions
     * @param array<string,mixed>                                        $context
     */
    private function conditionsMatch(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'];
            if ( ! array_key_exists($field, $context)) {
                return false;
            }

            $actual = $context[ $field ];

            if ($condition['operator'] === 'exists') {
                if ($actual === null || $actual === '' || $actual === []) {
                    return false;
                }

                continue;
            }

            if ( ! $this->valueMatches($actual, $condition['operator'], $condition['value'])) {
                return false;
            }
        }

        return true;
    }

    private function valueMatches(mixed $actual, string $operator, mixed $expected): bool
    {
        if ($operator === 'in') {
            if ( ! is_array($expected)) {
                return false;
            }

            foreach ($expected as $expectedValue) {
                if ($this->valueMatches($actual, 'equals', $expectedValue)) {
                    return true;
                }
            }

            return false;
        }

        if (is_array($actual)) {
            foreach ($actual as $actualValue) {
                if ($this->valueMatches($actualValue, $operator, $expected)) {
                    return true;
                }
            }

            return false;
        }

        if ( ! is_scalar($actual) || ! is_scalar($expected)) {
            return false;
        }

        if ($operator === 'equals') {
            return $this->scalarEquals($actual, $expected);
        }

        if ( ! is_string($actual) && ! is_string($expected)) {
            return false;
        }

        $actualText = strtolower(substr( (string) $actual, 0, RoutingRuleNormalizer::MAX_MATCH_LENGTH));
        $expectedText = strtolower(substr( (string) $expected, 0, RoutingRuleNormalizer::MAX_VALUE_LENGTH));

        return match ($operator) {
            'contains' => $expectedText !== '' && str_contains($actualText, $expectedText),
            'starts_with' => $expectedText !== '' && str_starts_with($actualText, $expectedText),
            'ends_with' => $expectedText !== '' && str_ends_with($actualText, $expectedText),
            default => false,
        };
    }

    private function scalarEquals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return (bool) $actual === (bool) $expected;
        }

        if (is_int($actual) || is_int($expected)) {
            return (int) $actual === (int) $expected;
        }

        if (is_float($actual) || is_float($expected)) {
            return (float) $actual === (float) $expected;
        }

        if ( ! is_scalar($actual) || ! is_scalar($expected)) {
            return false;
        }

        return strcasecmp( (string) $actual, (string) $expected) === 0;
    }

    /**
     * @param array<string,mixed> $provider
     */
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
