<?php

declare(strict_types=1);

namespace OneSMTP\Dispatch;

use InvalidArgumentException;
use OneSMTP\Product\FeatureGate;

/**
 * Runs a routing decision without entering the delivery pipeline.
 *
 * This service returns decision metadata only. Sample values and the transient
 * routing context never leave the request and are not accepted by any audit or
 * persistence abstraction.
 */
final class RoutingRuleSimulator
{
    public function __construct(
        private ?FeatureGate $featureGate = null,
        private ?RoutingRuleNormalizer $normalizer = null,
        private ?RoutingContextBuilder $contextBuilder = null,
        private ?RoutingRuleEvaluator $evaluator = null
    ) {
        $this->featureGate = $featureGate ?? new FeatureGate();
        $this->normalizer = $normalizer ?? new RoutingRuleNormalizer();
        $this->contextBuilder = $contextBuilder ?? new RoutingContextBuilder();
        $this->evaluator = $evaluator ?? new RoutingRuleEvaluator($this->featureGate, $this->normalizer);
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     * @param array<string,mixed>            $sample
     * @param array<int,array<string,mixed>> $providers
     * @return array<string,mixed>
     */
    public function simulate(array $rules, array $sample, array $providers, bool $candidate = false): array
    {
        if ( ! $this->featureGate->isEnabled(FeatureGate::SMART_ROUTING)) {
            return $this->result('pro_required');
        }

        if ($candidate && $rules === []) {
            return $this->result('candidate_empty');
        }

        if ( ! $this->hasSample($sample)) {
            return $this->result('sample_empty');
        }

        try {
            $normalizedRules = $this->normalizer->normalizeRules($rules, true);
        } catch (InvalidArgumentException) {
            return $this->result('candidate_invalid');
        }

        $context = $this->contextBuilder->fromPayload($this->samplePayload($sample));
        $decision = $this->evaluator->evaluateDetailed($normalizedRules, $context, $providers);
        $providerNames = $this->providerNames($providers);
        $effects = [];
        foreach ($decision['provider_effects'] as $effect) {
            $providerId = $effect['provider_id'];
            $effects[] = [
                'provider_id' => $providerId,
                'provider_name' => $providerNames[ $providerId ] ?? __('Unavailable provider', 'onesmtp'),
                'state' => $effect['state'],
            ];
        }

        $result = [
            'status' => $decision['status'],
            'provider_id' => $decision['provider_id'],
            'provider_name' => $decision['provider_id'] !== null
                ? ($providerNames[ $decision['provider_id'] ] ?? __('Unavailable provider', 'onesmtp'))
                : '',
            'rule_id' => $decision['rule_id'],
            'eligible_provider_ids' => $decision['eligible_provider_ids'],
            'provider_effects' => $effects,
        ];

        $truncatedFields = $this->truncatedFields($sample);
        if ($truncatedFields !== []) {
            $result['truncated_fields'] = $truncatedFields;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $sample
     */
    private function hasSample(array $sample): bool
    {
        foreach ($sample as $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_scalar($item) && trim( (string) $item) !== '') {
                        return true;
                    }
                }
                continue;
            }

            if (is_scalar($value) && trim( (string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $sample
     * @return array<string,mixed>
     */
    private function samplePayload(array $sample): array
    {
        return [
            'from' => $sample['sender'] ?? '',
            'to' => $sample['recipient'] ?? '',
            'subject' => $sample['subject'] ?? '',
            'message' => $sample['content'] ?? '',
            'onesmtp_source' => [
                'type' => $sample['source_type'] ?? '',
                'slug' => $sample['source_slug'] ?? '',
                'name' => $sample['source_name'] ?? '',
                'origin' => $sample['source_origin'] ?? '',
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $providers
     * @return array<int,string>
     */
    private function providerNames(array $providers): array
    {
        $names = [];
        foreach ($providers as $provider) {
            if ( ! is_array($provider)) {
                continue;
            }

            $id = (int) ($provider['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $names[ $id ] = sanitize_text_field( (string) ($provider['name'] ?? __('Provider', 'onesmtp')));
        }

        return $names;
    }

    /**
     * @param array<string,mixed> $sample
     * @return array<int,string>
     */
    private function truncatedFields(array $sample): array
    {
        $fields = [];
        foreach (['sender', 'recipient', 'subject', 'content', 'source_type', 'source_slug', 'source_name', 'source_origin'] as $field) {
            $value = $sample[ $field ] ?? '';
            if (is_array($value)) {
                $value = implode(',', array_map(static fn (mixed $item): string => (string) $item, $value));
            }

            if (is_scalar($value) && strlen( (string) $value) > RoutingRuleNormalizer::MAX_MATCH_LENGTH) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $status): array
    {
        return [
            'status' => $status,
            'provider_id' => null,
            'provider_name' => '',
            'rule_id' => null,
            'eligible_provider_ids' => [],
            'provider_effects' => [],
        ];
    }
}
