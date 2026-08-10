<?php

declare(strict_types=1);

namespace OneSMTP\Dispatch;

use InvalidArgumentException;

/**
 * Normalizes the small, admin-owned rule contract shared by storage and the
 * in-memory evaluator. Regex and arbitrary field access are deliberately not
 * supported, which keeps matching bounded and predictable.
 */
final class RoutingRuleNormalizer
{
    public const MAX_RULES = 50;
    public const MAX_CONDITIONS = 5;
    public const MAX_VALUE_LENGTH = 500;
    public const MAX_MATCH_LENGTH = 4096;
    public const MAX_IN_VALUES = 10;

    /** @var array<int,string> */
    public const FIELDS = [
        'sender',
        'recipient',
        'subject',
        'content',
        'source',
        'source_type',
        'source_slug',
        'source_name',
        'source_origin',
    ];

    /** @var array<int,string> */
    public const OPERATORS = ['equals', 'contains', 'starts_with', 'ends_with', 'in', 'exists'];

    /**
     * @param mixed $rules
     * @return array<int,array<string,mixed>>
     */
    public function normalizeRules(mixed $rules, bool $strict = false): array
    {
        if ( ! is_array($rules)) {
            $this->reject('Routing rules must be a list.', $strict);
            return [];
        }

        if (count($rules) > self::MAX_RULES) {
            $this->reject('A maximum of 50 routing rules can be configured.', $strict);
            return [];
        }

        $normalized = [];
        $nextId = 1;
        foreach ($rules as $rule) {
            try {
                $normalizedRule = $this->normalizeRule($rule, $strict);
            } catch (InvalidArgumentException $exception) {
                if ($strict) {
                    throw $exception;
                }

                continue;
            }

            if ($normalizedRule === null) {
                continue;
            }

            $id = (int) ($normalizedRule['id'] ?? 0);
            if ($id <= 0) {
                $normalizedRule['id'] = $nextId;
            }
            $nextId = max($nextId, (int) $normalizedRule['id'] + 1);
            $normalized[] = $normalizedRule;
        }

        return $normalized;
    }

    /**
     * @param mixed $rule
     * @return array<string,mixed>|null
     */
    public function normalizeRule(mixed $rule, bool $strict = false): ?array
    {
        if ( ! is_array($rule)) {
            $this->reject('Each routing rule must be an object.', $strict);
            return null;
        }

        $providerId = (int) ($rule['provider_id'] ?? 0);
        if ($providerId <= 0) {
            $this->reject('Choose an eligible provider for each routing rule.', $strict);
            return null;
        }

        $priority = (int) ($rule['priority'] ?? 100);
        if ($priority < 1 || $priority > 9999) {
            $this->reject('Routing priority must be between 1 and 9999.', $strict);
            return null;
        }

        $conditions = $this->normalizeConditions($rule['conditions'] ?? null, $strict);
        if ($conditions === []) {
            $this->reject('Add at least one valid routing condition.', $strict);
            return null;
        }

        $id = (int) ($rule['id'] ?? 0);

        return [
            'id' => $id > 0 ? $id : 0,
            'provider_id' => $providerId,
            'priority' => $priority,
            'enabled' => ! array_key_exists('enabled', $rule) || $this->isTruthy($rule['enabled']),
            'conditions' => $conditions,
        ];
    }

    /**
     * @param mixed $conditions
     * @return array<int,array{field:string,operator:string,value:mixed}>
     */
    public function normalizeConditions(mixed $conditions, bool $strict = false): array
    {
        if ( ! is_array($conditions)) {
            $this->reject('Routing conditions must be a list.', $strict);
            return [];
        }

        if (count($conditions) > self::MAX_CONDITIONS) {
            $this->reject('A routing rule can contain at most 5 conditions.', $strict);
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
                $this->reject('Each routing condition must be an object.', $strict);
                return [];
            }

            $conditionField = strtolower(trim( (string) ($condition['field'] ?? '')));
            if ( ! in_array($conditionField, self::FIELDS, true)) {
                $this->reject('This routing condition field is not supported.', $strict);
                return [];
            }

            $operator = strtolower(trim( (string) ($condition['operator'] ?? 'equals')));
            if ( ! in_array($operator, self::OPERATORS, true)) {
                $this->reject('This routing condition operator is not supported.', $strict);
                return [];
            }

            $value = $condition['value'] ?? null;
            if ($operator === 'exists') {
                $value = null;
            } elseif ($operator === 'in') {
                $value = $this->normalizeList($value, $strict);
                if ($value === []) {
                    $this->reject('The “in” operator needs at least one value.', $strict);
                    return [];
                }
            } else {
                $value = $this->normalizeValue($value, $strict);
                if ($value === '') {
                    $this->reject('Routing condition values cannot be empty.', $strict);
                    return [];
                }
            }

            $normalized[] = [
                'field' => $conditionField,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value, bool $strict): string
    {
        if ( ! is_scalar($value)) {
            $this->reject('Routing condition values must be scalar.', $strict);
            return '';
        }

        $value = trim(sanitize_textarea_field( (string) $value));
        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            $this->reject('Routing condition values are limited to 500 characters.', $strict);
            return '';
        }

        return $value;
    }

    /**
     * @return array<int,string>
     */
    private function normalizeList(mixed $value, bool $strict): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if ( ! is_array($value) || count($value) > self::MAX_IN_VALUES) {
            $this->reject('The “in” operator supports at most 10 values.', $strict);
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $item = $this->normalizeValue($item, $strict);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function reject(string $message, bool $strict): void
    {
        if ($strict) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are fixed validation copy, never user content.
            throw new InvalidArgumentException($message);
        }
    }
}
