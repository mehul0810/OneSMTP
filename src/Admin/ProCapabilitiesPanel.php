<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Product\FeatureGate;

final class ProCapabilitiesPanel
{
    public function __construct(private FeatureGate $features)
    {
    }

    public function render(): void
    {
        echo '<section class="onesmtp-settings-panel onesmtp-settings-panel--full onesmtp-pro-capabilities postbox">';
        echo '<div class="postbox-header"><h3 class="hndle">' . esc_html__('Pro capabilities', 'onesmtp') . '</h3></div>';
        echo '<div class="inside">';
        echo '<p class="description onesmtp-settings-panel-description">' . esc_html__('Optional Pro modules remain isolated from core delivery. A feature runs only when the site has Pro access and its rollout flag is enabled.', 'onesmtp') . '</p>';
        echo '<div class="onesmtp-pro-capability-list">';

        foreach ($this->catalog() as $feature => $content) {
            $state = $this->features->state($feature);
            $enabled = $content['availability'] === 'shipped' && $state === FeatureGate::STATE_ENABLED;

            echo '<div class="onesmtp-pro-capability-item">';
            echo '<div class="onesmtp-pro-capability-copy"><strong>' . esc_html($content['label']) . '</strong><p>' . esc_html($content['description']) . '</p></div>';
            echo '<div class="onesmtp-pro-capability-status">';
            echo '<span class="onesmtp-status-pill ' . ($enabled ? 'is-ready' : 'is-pending') . '">' . esc_html($this->stateLabel($state, $content['availability'])) . '</span>';

            if ( ! $enabled) {
                echo '<button type="button" class="button button-secondary" disabled aria-disabled="true">' . esc_html($this->disabledActionLabel($state, $content['availability'])) . '</button>';
            }

            echo '</div></div>';
        }

        echo '</div>';
        echo '<p class="description onesmtp-pro-capabilities-note">' . esc_html__('No purchase, license activation, or upgrade URL is included in this candidate. “Available with Pro” describes a capability boundary only; disabled controls are inert. Core sending, providers, failover, queues, and logs remain available without Pro.', 'onesmtp') . '</p>';
        echo '</div></section>';
    }

    /** @return array<string,array{label:string,description:string,availability:'shipped'|'planned'}> */
    private function catalog(): array
    {
        return [
            FeatureGate::SMART_ROUTING => [
                'label' => __('Smart routing rules', 'onesmtp'),
                'description' => __('Route mail with bounded sender, recipient, subject, content, and source conditions while core provider priority and failover remain available.', 'onesmtp'),
                'availability' => 'shipped',
            ],
            FeatureGate::PROVIDER_EVENTS => [
                'label' => __('Provider events and suppression', 'onesmtp'),
                'description' => __('Provider event ingestion and suppression controls are planned. Current provider delivery, tests, and logs remain available without them.', 'onesmtp'),
                'availability' => 'planned',
            ],
            FeatureGate::ADVANCED_ANALYTICS => [
                'label' => __('Advanced analytics', 'onesmtp'),
                'description' => __('Compare bounded provider reliability and delivery report slices from aggregate site history; scores are not inbox-placement or provider-SLA measurements.', 'onesmtp'),
                'availability' => 'shipped',
            ],
            FeatureGate::COMPLIANCE_CONTROLS => [
                'label' => __('Compliance controls', 'onesmtp'),
                'description' => __('Apply bounded site-local retention presets or custom duration and fixed privacy-safe export profiles.', 'onesmtp'),
                'availability' => 'shipped',
            ],
            FeatureGate::MULTISITE_MANAGEMENT => [
                'label' => __('Multisite management', 'onesmtp'),
                'description' => __('Network-level settings and log views are planned; current settings and logs remain site-local.', 'onesmtp'),
                'availability' => 'planned',
            ],
            FeatureGate::ADVANCED_ALERTS => [
                'label' => __('Advanced alert escalation', 'onesmtp'),
                'description' => __('Escalate repeated terminal failures to validated email or HTTPS webhook destinations without changing core alerts.', 'onesmtp'),
                'availability' => 'shipped',
            ],
        ];
    }

    private function stateLabel(string $state, string $availability): string
    {
        if ($availability === 'planned') {
            return __('Planned', 'onesmtp');
        }

        return match ($state) {
            FeatureGate::STATE_ENABLED => __('Enabled', 'onesmtp'),
            FeatureGate::STATE_FLAG_DISABLED => __('Not enabled', 'onesmtp'),
            default => __('Available with Pro', 'onesmtp'),
        };
    }

    private function disabledActionLabel(string $state, string $availability): string
    {
        if ($availability === 'planned') {
            return __('Not available yet', 'onesmtp');
        }

        return $state === FeatureGate::STATE_FLAG_DISABLED
            ? __('Unavailable on this site', 'onesmtp')
            : __('Requires Pro', 'onesmtp');
    }
}
