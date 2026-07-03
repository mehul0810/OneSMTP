<?php

declare(strict_types=1);

namespace OneSMTP\Dispatch;

final class RoutingRuleEvaluator
{
    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed>            $context
     * @param array<int,array<string,mixed>> $providers
     */
    public function evaluate(array $rules, array $context, array $providers): ?int
    {
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

            $conditions = $this->normalizeConditions($rule['conditions'] ?? null);
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
     * @return array<int,array{field:string,operator:string,value:mixed}>
     */
    private function normalizeConditions(mixed $conditions): array
    {
        if ( ! is_array($conditions)) {
            return [];
        }

        $normalized = [];

        foreach ($conditions as $field => $condition) {
            if (is_string($field) && ! is_array($condition)) {
                $condition = [
                    'field' => $field,
                    'operator' => 'equals',
                    'value' => $condition,
                ];
            }

            if ( ! is_array($condition)) {
                return [];
            }

            $conditionField = isset($condition['field']) ? (string) $condition['field'] : '';
            if ( ! $this->isSafeFieldName($conditionField)) {
                return [];
            }

            $operator = isset($condition['operator']) ? strtolower( (string) $condition['operator']) : 'equals';
            if ( ! in_array($operator, ['equals', 'in', 'exists'], true)) {
                return [];
            }

            $normalized[] = [
                'field' => $conditionField,
                'operator' => $operator,
                'value' => $condition['value'] ?? null,
            ];
        }

        return $normalized;
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
                if ($actual === null || $actual === '') {
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
                if ($this->scalarEquals($actual, $expectedValue)) {
                    return true;
                }
            }

            return false;
        }

        return $this->scalarEquals($actual, $expected);
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

        return (string) $actual === (string) $expected;
    }

    private function isSafeFieldName(string $field): bool
    {
        if ( ! preg_match('/^[a-z0-9_.:-]+$/', $field)) {
            return false;
        }

        foreach (['body', 'content', 'header', 'recipient', 'email', 'secret', 'token', 'credential', 'password', 'payload'] as $blocked) {
            if (str_contains($field, $blocked)) {
                return false;
            }
        }

        return true;
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
