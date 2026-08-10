<?php

declare(strict_types=1);

namespace OneSMTP\Dispatch;

use InvalidArgumentException;

final class RoutingRulesRepository
{
    private const OPTION_KEY = 'onesmtp_routing_rules';

    public function __construct(private RoutingRuleNormalizer $normalizer = new RoutingRuleNormalizer())
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get(): array
    {
        return $this->normalizer->normalizeRules(get_option(self::OPTION_KEY, []));
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     */
    public function save(array $rules): bool
    {
        $normalized = $this->normalizer->normalizeRules($rules, true);

        return (bool) update_option(self::OPTION_KEY, $normalized, false);
    }

    /**
     * @param array<string,mixed> $rule
     */
    public function add(array $rule): bool
    {
        $rules = $this->get();
        if (count($rules) >= RoutingRuleNormalizer::MAX_RULES) {
            throw new InvalidArgumentException('A maximum of 50 routing rules can be configured.');
        }

        $normalized = $this->normalizer->normalizeRule($rule, true);
        if ($normalized === null) {
            return false;
        }

        $rules[] = $normalized;

        return $this->save($rules);
    }

    public function delete(int $ruleId): bool
    {
        if ($ruleId <= 0) {
            return false;
        }

        $rules = $this->get();
        $filtered = array_values(array_filter(
            $rules,
            static fn (array $rule): bool => (int) ($rule['id'] ?? 0) !== $ruleId
        ));

        if (count($filtered) === count($rules)) {
            return false;
        }

        return $this->save($filtered);
    }

    /**
     * @param array<string,mixed> $rule
     */
    public function update(int $ruleId, array $rule): bool
    {
        if ($ruleId <= 0) {
            return false;
        }

        $rules = $this->get();
        $updated = false;
        foreach ($rules as $index => $storedRule) {
            if ( (int) ($storedRule['id'] ?? 0) !== $ruleId) {
                continue;
            }

            $rule['id'] = $ruleId;
            $normalized = $this->normalizer->normalizeRule($rule, true);
            if ($normalized === null) {
                return false;
            }

            $rules[ $index ] = $normalized;
            $updated = true;
            break;
        }

        return $updated && $this->save(array_values($rules));
    }
}
