<?php

declare(strict_types=1);

namespace OneSMTP\Multisite;

use OneSMTP\Core\Capabilities;
use OneSMTP\Product\FeatureGate;

/**
 * Stores the small, non-secret settings surface shared by network sites.
 *
 * Provider records, sender addresses, alert destinations, and other payload
 * data intentionally remain site-local. Only the operational controls below
 * are eligible for network defaults or a site override.
 */
final class NetworkSettingsRepository
{
    public const NETWORK_OPTION = 'onesmtp_network_settings';
    public const SITE_OPTION = 'onesmtp_multisite_settings';

    public const RATE_LIMITS = 'rate_limits';
    public const BACKGROUND_SENDING = 'background_sending';
    public const ATTACHMENT_LOGGING = 'attachment_logging';

    /** @var array<int,string> */
    private const GROUPS = [
        self::RATE_LIMITS,
        self::BACKGROUND_SENDING,
        self::ATTACHMENT_LOGGING,
    ];

    public function __construct(private ?FeatureGate $featureGate = null)
    {
        $this->featureGate = $featureGate ?? FeatureGate::fromRuntime();
    }

    public function isAvailable(): bool
    {
        return function_exists('is_multisite')
            && is_multisite()
            && $this->featureGate->isEnabled(FeatureGate::MULTISITE_MANAGEMENT);
    }

    /**
     * @return array{version:int,defaults:array<string,array<string,mixed>>,default_inheritance:array<string,bool>}
     */
    public function getNetwork(): array
    {
        $raw = function_exists('get_site_option') ? get_site_option(self::NETWORK_OPTION, []) : [];

        return $this->normalizeNetwork(is_array($raw) ? $raw : []);
    }

    /**
     * @return array{version:int,inheritance:array<string,bool>,overrides:array<string,array<string,mixed>>}
     */
    public function getSite(): array
    {
        $raw = function_exists('get_option') ? get_option(self::SITE_OPTION, []) : [];

        return $this->normalizeSite(is_array($raw) ? $raw : []);
    }

    /**
     * Resolve one allowlisted group without copying network data into a site
     * option. A site can explicitly inherit or explicitly retain an override.
     *
     * @param array<string,mixed> $local
     * @return array<string,mixed>
     */
    public function resolve(string $group, array $local): array
    {
        if ( ! $this->isAvailable() || ! in_array($group, self::GROUPS, true)) {
            return $local;
        }

        $network = $this->getNetwork();
        $site = $this->getSite();
        $inherit = array_key_exists($group, $site['inheritance'])
            ? $site['inheritance'][ $group ]
            : ($network['default_inheritance'][ $group ] ?? false);

        if ($inherit) {
            return array_replace($local, $network['defaults'][ $group ] ?? []);
        }

        return array_replace($local, $site['overrides'][ $group ] ?? []);
    }

    /**
     * @param array<string,mixed> $defaults
     * @param array<string,bool> $inheritance
     */
    public function saveNetwork(array $defaults, array $inheritance): bool
    {
        if ( ! $this->isAvailable() || ! Capabilities::canManageNetwork($this->featureGate) || ! function_exists('update_site_option')) {
            return false;
        }

        $normalized = $this->normalizeNetwork([
            'defaults' => $defaults,
            'default_inheritance' => $inheritance,
        ]);

        return (bool) update_site_option(self::NETWORK_OPTION, $normalized);
    }

    /**
     * @param array<string,mixed> $overrides
     * @param array<string,bool> $inheritance
     */
    public function saveSite(int $siteId, array $overrides, array $inheritance): bool
    {
        if ( ! $this->isAvailable() || ! Capabilities::canManageNetwork($this->featureGate) || $siteId <= 0 || ! function_exists('switch_to_blog') || ! function_exists('update_option')) {
            return false;
        }

        $currentBlogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        if ( ! switch_to_blog($siteId)) {
            return false;
        }

        try {
            $normalized = $this->normalizeSite([
                'overrides' => $overrides,
                'inheritance' => $inheritance,
            ]);

            return (bool) update_option(self::SITE_OPTION, $normalized, false);
        } finally {
            if (function_exists('restore_current_blog')) {
                restore_current_blog();
            } elseif ($currentBlogId > 0 && function_exists('switch_to_blog')) {
                switch_to_blog($currentBlogId);
            }
        }
    }

    /** @return array<int,string> */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    /** @param array<string,mixed> $raw */
    private function normalizeNetwork(array $raw): array
    {
        $rawDefaults = isset($raw['defaults']) && is_array($raw['defaults']) ? $raw['defaults'] : [];
        $rawInheritance = isset($raw['default_inheritance']) && is_array($raw['default_inheritance']) ? $raw['default_inheritance'] : [];
        $defaults = [];
        $inheritance = [];

        foreach (self::GROUPS as $group) {
            $defaults[ $group ] = $this->normalizeGroup($group, isset($rawDefaults[ $group ]) && is_array($rawDefaults[ $group ]) ? $rawDefaults[ $group ] : []);
            $inheritance[ $group ] = ! empty($rawInheritance[ $group ]);
        }

        return [
            'version' => 1,
            'defaults' => $defaults,
            'default_inheritance' => $inheritance,
        ];
    }

    /** @param array<string,mixed> $raw */
    private function normalizeSite(array $raw): array
    {
        $rawOverrides = isset($raw['overrides']) && is_array($raw['overrides']) ? $raw['overrides'] : [];
        $rawInheritance = isset($raw['inheritance']) && is_array($raw['inheritance']) ? $raw['inheritance'] : [];
        $overrides = [];
        $inheritance = [];

        foreach (self::GROUPS as $group) {
            if (isset($rawOverrides[ $group ]) && is_array($rawOverrides[ $group ])) {
                $overrides[ $group ] = $this->normalizeGroup($group, $rawOverrides[ $group ]);
            }
            if (array_key_exists($group, $rawInheritance)) {
                $inheritance[ $group ] = ! empty($rawInheritance[ $group ]);
            }
        }

        return [
            'version' => 1,
            'inheritance' => $inheritance,
            'overrides' => $overrides,
        ];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function normalizeGroup(string $group, array $values): array
    {
        if ($group === self::RATE_LIMITS) {
            return [
                'per_minute' => max(0, min(100000, (int) ($values['per_minute'] ?? 0))),
                'per_hour' => max(0, min(1000000, (int) ($values['per_hour'] ?? 0))),
                'per_day' => max(0, min(10000000, (int) ($values['per_day'] ?? 0))),
            ];
        }

        return ['enabled' => ! empty($values['enabled'])];
    }
}
