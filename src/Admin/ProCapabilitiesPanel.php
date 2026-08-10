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
            $enabled = $state === FeatureGate::STATE_ENABLED;

            echo '<div class="onesmtp-pro-capability-item">';
            echo '<div class="onesmtp-pro-capability-copy"><strong>' . esc_html($content['label']) . '</strong><p>' . esc_html($content['description']) . '</p></div>';
            echo '<div class="onesmtp-pro-capability-status">';
            echo '<span class="onesmtp-status-pill ' . ($enabled ? 'is-ready' : 'is-pending') . '">' . esc_html($this->stateLabel($state)) . '</span>';

            if ( ! $enabled) {
                echo '<button type="button" class="button button-secondary" disabled aria-disabled="true">' . esc_html($this->disabledActionLabel($state)) . '</button>';
            }

            echo '</div></div>';
        }

        echo '</div>';
        echo '<p class="description onesmtp-pro-capabilities-note">' . esc_html__('License and upgrade controls will appear here when Pro distribution is available. Core sending, failover, queues, and logs remain available without Pro.', 'onesmtp') . '</p>';
        echo '</div></section>';
    }

    /** @return array<string,array{label:string,description:string}> */
    private function catalog(): array
    {
        return [
            FeatureGate::SMART_ROUTING => [
                'label' => __('Smart routing rules', 'onesmtp'),
                'description' => __('Route mail using sender, recipient, subject, content, and source conditions.', 'onesmtp'),
            ],
            FeatureGate::PROVIDER_EVENTS => [
                'label' => __('Provider events and suppression', 'onesmtp'),
                'description' => __('Process delivery webhooks, bounces, complaints, and suppression decisions.', 'onesmtp'),
            ],
            FeatureGate::ADVANCED_ANALYTICS => [
                'label' => __('Advanced analytics', 'onesmtp'),
                'description' => __('Compare provider reliability, delivery trends, and operational SLA signals.', 'onesmtp'),
            ],
            FeatureGate::COMPLIANCE_CONTROLS => [
                'label' => __('Compliance controls', 'onesmtp'),
                'description' => __('Apply configurable retention, audit, and privacy-safe export policies.', 'onesmtp'),
            ],
            FeatureGate::MULTISITE_MANAGEMENT => [
                'label' => __('Multisite management', 'onesmtp'),
                'description' => __('Manage network-level settings and delivery visibility across sites.', 'onesmtp'),
            ],
            FeatureGate::ADVANCED_ALERTS => [
                'label' => __('Advanced alert escalation', 'onesmtp'),
                'description' => __('Escalate repeated failures and high-priority message types to multiple safe destinations.', 'onesmtp'),
            ],
        ];
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            FeatureGate::STATE_ENABLED => __('Enabled', 'onesmtp'),
            FeatureGate::STATE_FLAG_DISABLED => __('Not enabled', 'onesmtp'),
            default => __('Available with Pro', 'onesmtp'),
        };
    }

    private function disabledActionLabel(string $state): string
    {
        return $state === FeatureGate::STATE_FLAG_DISABLED
            ? __('Unavailable on this site', 'onesmtp')
            : __('Requires Pro', 'onesmtp');
    }
}
