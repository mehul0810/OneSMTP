<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Repository\ProviderRepository;

/** Renders the focused test-delivery workspace. */
final class DeliveryAdmin
{
    private const ACTION_NAME = 'onesmtp_setup_action';
    private const NONCE_NAME = 'onesmtp_setup_nonce';

    public function __construct(private ProviderRepository $providers)
    {
    }

    public function render(): void
    {
        if ( ! Capabilities::canManage()) {
            return;
        }

        $providers = $this->providers->getAllSafe();
        $active = array_values(array_filter($providers, static fn (array $provider): bool => ! empty($provider['is_active'])));
        $enabled = $active !== [];

        echo '<div class="onesmtp-delivery-workspace">';
        echo '<section class="onesmtp-delivery-card">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Heroicons renders fixed local SVG paths and escapes the only dynamic attribute.
        echo '<div class="onesmtp-delivery-card-heading"><span class="onesmtp-delivery-icon" aria-hidden="true">' . Heroicons::render('paper-airplane') . '</span><div><h3>' . esc_html__('Send a test email', 'onesmtp') . '</h3><p>' . esc_html__('Verify your provider and sender identity before relying on production email.', 'onesmtp') . '</p></div></div>';
        echo '<form method="post" class="onesmtp-delivery-form">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="send_test">';
        echo '<label for="onesmtp-delivery-test-to">' . esc_html__('Send test to', 'onesmtp') . '</label>';
        echo '<input id="onesmtp-delivery-test-to" class="regular-text" type="email" name="test_to" value="' . esc_attr( (string) get_option('admin_email')) . '" placeholder="email@example.com"' . ($enabled ? '' : ' disabled') . ' required>';
        if ($enabled) {
            echo '<label for="onesmtp-delivery-provider" class="screen-reader-text">' . esc_html__('Provider', 'onesmtp') . '</label><select id="onesmtp-delivery-provider" name="provider_id">';
            foreach ($active as $provider) {
                echo '<option value="' . esc_attr( (string) ( (int) ($provider['id'] ?? 0))) . '">' . esc_html( (string) ($provider['name'] ?? '')) . '</option>';
            }
            echo '</select>';
        }
        echo '<p class="description">' . esc_html($enabled ? __('A test message will be sent using the selected active provider.', 'onesmtp') : __('Connect a provider to enable test delivery.', 'onesmtp')) . '</p>';
        submit_button(__('Send test email', 'onesmtp'), 'primary', 'submit', false, $enabled ? [] : ['disabled' => 'disabled']);
        echo '</form></section></div>';
    }
}
