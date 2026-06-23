<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Providers\ProviderTypes;

final class ProviderCapabilityMatrix
{
    public function render(): void
    {
        $metadata     = ProviderTypes::metadata();
        $capabilities = ProviderTypes::capabilityLabels();

        echo '<h3>' . esc_html__('Provider capability matrix', 'onesmtp') . '</h3>';
        echo '<p>' . esc_html__('Compare supported delivery paths before entering provider credentials. Unavailable capabilities do not block setup.', 'onesmtp') . '</p>';

        if ($metadata === [] || $capabilities === []) {
            echo '<p>' . esc_html__('Provider capability metadata is not available.', 'onesmtp') . '</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Provider', 'onesmtp') . '</th>';
        foreach ($capabilities as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '<th scope="col">' . esc_html__('Notes', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($metadata as $type => $provider) {
            $providerCapabilities = $provider['capabilities'];
            $notes = $provider['notes'];

            echo '<tr>';
            echo '<th scope="row"><strong>' . esc_html($provider['label']) . '</strong><br><code>' . esc_html($type) . '</code><p class="description">' . esc_html($provider['description']) . '</p></th>';

            foreach (array_keys($capabilities) as $capability) {
                $available = ! empty($providerCapabilities[$capability]);
                $label = $available ? __('Available', 'onesmtp') : __('Unavailable', 'onesmtp');

                echo '<td><span aria-label="' . esc_attr($label) . '">' . esc_html($label) . '</span></td>';
            }

            echo '<td>' . esc_html(implode(' ', array_filter($notes))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
