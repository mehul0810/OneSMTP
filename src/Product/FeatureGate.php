<?php

declare(strict_types=1);

namespace OneSMTP\Product;

/**
 * Central default-deny gate for optional Pro modules.
 *
 * Entitlement and rollout flags are intentionally separate: a valid Pro
 * entitlement must never activate unfinished or disabled behavior by itself.
 */
final class FeatureGate
{
    public const SMART_ROUTING = 'smart_routing';
    public const PROVIDER_EVENTS = 'provider_events';
    public const ADVANCED_ANALYTICS = 'advanced_analytics';
    public const COMPLIANCE_CONTROLS = 'compliance_controls';
    public const MULTISITE_MANAGEMENT = 'multisite_management';
    public const ADVANCED_ALERTS = 'advanced_alerts';
    public const PROVIDER_QUOTA_BUDGETS = 'provider_quota_budgets';
    public const BOUNCE_SUPPRESSION = 'bounce_suppression';

    public const STATE_ENABLED = 'enabled';
    public const STATE_FLAG_DISABLED = 'flag_disabled';
    public const STATE_UPGRADE_REQUIRED = 'upgrade_required';
    public const STATE_UNKNOWN = 'unknown';

    private const FEATURES = [
        self::SMART_ROUTING,
        self::PROVIDER_EVENTS,
        self::ADVANCED_ANALYTICS,
        self::COMPLIANCE_CONTROLS,
        self::MULTISITE_MANAGEMENT,
        self::ADVANCED_ALERTS,
        self::PROVIDER_QUOTA_BUDGETS,
        self::BOUNCE_SUPPRESSION,
    ];

    /** @var array<string,bool> */
    private array $flags = [];

    /**
     * @param array<string,bool> $flags Internal rollout flags keyed by feature ID.
     */
    public function __construct(array $flags = [], private bool $proEntitled = false)
    {
        foreach (self::FEATURES as $feature) {
            $this->flags[ $feature ] = isset( $flags[ $feature ] ) && $flags[ $feature ] === true;
        }
    }

    /**
     * Build the gate from extension points supplied by a future Pro runtime.
     *
     * The free plugin ships with both values disabled. No licensing request or
     * network lookup is performed here; an installed Pro component may provide
     * the entitlement and rollout state through these filters.
     */
    public static function fromRuntime(): self
    {
        $flags = apply_filters('onesmtp_feature_flags', []);
        $proFlags = apply_filters('onesmtp_pro_feature_flags', []);
        if (is_array($proFlags)) {
            $flags = array_merge(is_array($flags) ? $flags : [], $proFlags);
        }
        $entitled = apply_filters('onesmtp_pro_entitled', false);

        return new self(
            is_array($flags) ? $flags : [],
            $entitled === true
        );
    }

    public static function fromWordPress(): self
    {
        return self::fromRuntime();
    }

    /** @return array<int,string> */
    public static function featureIds(): array
    {
        return self::FEATURES;
    }

    public function isEnabled(string $feature): bool
    {
        return $this->state($feature) === self::STATE_ENABLED;
    }

    public function state(string $feature): string
    {
        if ( ! in_array($feature, self::FEATURES, true)) {
            return self::STATE_UNKNOWN;
        }

        if ( ! $this->proEntitled) {
            return self::STATE_UPGRADE_REQUIRED;
        }

        return $this->flags[ $feature ]
            ? self::STATE_ENABLED
            : self::STATE_FLAG_DISABLED;
    }

    public function hasProEntitlement(): bool
    {
        return $this->proEntitled;
    }
}
