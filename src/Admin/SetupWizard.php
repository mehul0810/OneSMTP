<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Core\Capabilities;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Settings\SenderIdentityRepository;

final class SetupWizard
{
    private const ACTION_NAME = 'onesmtp_setup_action';
    private const NONCE_NAME  = 'onesmtp_setup_nonce';

    private ProviderRepository $providers;
    private ProviderDeliveryManager $deliveryManager;
    private EventRepository $events;
    private AdminAuditLogger $auditLogger;
    private SenderIdentityRepository $senderIdentity;

    public function __construct(
        ?ProviderRepository $providers = null,
        ?ProviderDeliveryManager $deliveryManager = null,
        ?EventRepository $events = null,
        ?AdminAuditLogger $auditLogger = null,
        ?SenderIdentityRepository $senderIdentity = null
    ) {
        $this->providers       = $providers ?? new ProviderRepository();
        $this->deliveryManager = $deliveryManager ?? new ProviderDeliveryManager();
        $this->events          = $events ?? new EventRepository();
        $this->auditLogger     = $auditLogger ?? new AdminAuditLogger();
        $this->senderIdentity = $senderIdentity ?? new SenderIdentityRepository();
    }

    public function handleRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $action = isset($_POST[self::ACTION_NAME]) ? sanitize_key(wp_unslash((string) $_POST[self::ACTION_NAME])) : '';
        if ($action === '') {
            return;
        }

        if (! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to run the Aculect Mail setup wizard.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION_NAME, self::NONCE_NAME);

        if ($action === 'save_provider') {
            $provider = $this->normalizePostedProvider();
            $providerId = $this->providers->save($provider);
            if ($providerId > 0) {
                $this->events->add('setup_provider_saved', ['source' => 'setup_wizard'], null, $providerId);
                $this->auditLogger->logProviderChange('created', $providerId, [
                    'source' => 'setup_wizard',
                    'provider_name' => (string) ($provider['name'] ?? ''),
                    'adapter_type' => (string) ($provider['adapter_type'] ?? ''),
                    'is_active' => ! empty($provider['is_active']),
                    'safe_config_fields' => $this->safeConfigFieldNames($provider),
                    'credential_fields_updated' => $this->sensitiveConfigFieldNames($provider),
                ]);
            }

            $this->redirect($providerId > 0 ? 'provider_saved' : 'failed');
        }

        if ($action === 'send_test') {
            $this->handleTestEmail();
        }

        $this->redirect('failed');
    }

    public function render(): void
    {
        $providers = $this->providers->getAllSafe();

        echo '<div id="onesmtp-delivery" class="onesmtp-setup-shell is-simplified">';
        echo '<div class="onesmtp-setup-main">';
        echo '<div class="onesmtp-setup-notices">';
        $this->renderNotice();
        echo '</div>';

        $this->renderOverviewSetupCard($providers);
        $this->renderSenderIdentityMount();
        if (! $this->isSenderIdentityReady()) {
            $this->renderSenderIdentityInlineForm();
        }
        if ($this->hasActiveProvider($providers)) {
            $this->renderTestForm($providers);
            $this->renderCompletion($providers);
        }
        echo '</div>';
        echo '</div>';
    }

    /** @param array<int,array<string,mixed>> $providers */
    private function renderOverviewSetupCard(array $providers): void
    {
        $hasProvider = $this->hasActiveProvider($providers);
        $testSent = $this->latestStatus() === 'test_sent';
        $sender = $this->senderIdentity->get()->toArray();
        $senderReady = trim((string) ($sender['from_email'] ?? '')) !== '' && trim((string) ($sender['from_name'] ?? '')) !== '';
        $steps = [
            [__('Add a sender identity', 'onesmtp'), __('Add the name and email address you want to send from.', 'onesmtp'), $senderReady, 'user'],
            [__('Connect a provider', 'onesmtp'), __('Choose a provider and securely connect your account.', 'onesmtp'), $hasProvider, 'squares'],
            [__('Send a test email', 'onesmtp'), __('Send a test email to verify everything is working.', 'onesmtp'), $testSent, 'paper-airplane'],
        ];
        $firstIncomplete = 0;
        foreach ($steps as $index => $step) {
            if (! $step[2]) {
                $firstIncomplete = $index;
                break;
            }
            $firstIncomplete = count($steps);
        }

        echo '<section class="onesmtp-overview-setup-card">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Heroicons renders an SVG selected from a private allowlist and escapes its only dynamic attribute.
        echo '<div class="onesmtp-overview-setup-heading"><span class="onesmtp-overview-setup-icon" aria-hidden="true">' . Heroicons::render('envelope') . '</span><div><h3>' . esc_html($firstIncomplete >= count($steps) ? __('Setup complete', 'onesmtp') : __('Complete Aculect Mail setup', 'onesmtp')) . '</h3><p>' . esc_html__('Three essentials make WordPress email ready for production.', 'onesmtp') . '</p></div></div>';
        echo '<ol class="onesmtp-overview-steps">';
        foreach ($steps as $index => [$title, $description, $complete, $icon]) {
            $state = $complete ? 'is-complete' : ($index === $firstIncomplete ? 'is-current' : 'is-pending');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Heroicons renders an SVG selected from a private allowlist and escapes its only dynamic attribute.
            echo '<li class="onesmtp-overview-step ' . esc_attr($state) . '"><span class="onesmtp-overview-step-number">' . esc_html((string) ($index + 1)) . '</span><span class="onesmtp-overview-step-icon" aria-hidden="true">' . Heroicons::render($icon) . '</span><span class="onesmtp-overview-step-copy"><strong>' . esc_html($title) . '</strong><span>' . esc_html($description) . '</span></span></li>';
        }
        echo '</ol>';
        echo '<div class="onesmtp-overview-setup-actions"><span class="screen-reader-text">' . esc_html__('Save first provider. Add and activate a provider before sending a setup test email.', 'onesmtp') . '</span>';
        $ctaTarget = $firstIncomplete === 0 ? 'onesmtp-sender-identity-fallback' : 'onesmtp-sender-identity';
        $ctaUrl = $firstIncomplete === 0 ? '#' . $ctaTarget : admin_url('options-general.php?page=onesmtp&tab=onesmtp-providers#onesmtp-providers');
        $ctaLabel = $firstIncomplete === 0 ? __('Set up sender identity', 'onesmtp') : ($firstIncomplete === 1 ? __('Connect a provider', 'onesmtp') : __('Send a test email', 'onesmtp'));
        echo '<a class="button button-primary" data-onesmtp-reveal="' . esc_attr($ctaTarget) . '"';
        if ($firstIncomplete === 0) {
            echo ' data-onesmtp-drawer-trigger="sender-identity"';
        }
        echo ' href="' . esc_url($ctaUrl) . '">' . esc_html($ctaLabel) . '</a>';
        echo '<a class="onesmtp-overview-secondary-action" href="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-providers#onesmtp-providers')) . '">' . esc_html__('View providers', 'onesmtp') . '</a>';
        echo '</div>';
        echo '</section>';
    }

    private function renderSenderIdentityInlineForm(): void
    {
        $values = $this->senderIdentity->get()->toArray();
        echo '<details id="onesmtp-sender-identity-fallback" class="onesmtp-overview-inline-form"><summary>' . esc_html__('Sender identity', 'onesmtp') . '</summary>';
        echo '<p class="description">' . esc_html__('Choose the name and email address Aculect Mail should use when sending WordPress email.', 'onesmtp') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-overview#onesmtp-sender-identity-fallback')) . '">';
        echo '<input type="hidden" name="onesmtp_settings_action" value="save_sender_identity"><input type="hidden" name="onesmtp_return_tab" value="onesmtp-overview">';
        wp_nonce_field('onesmtp_save_settings', 'onesmtp_settings_nonce');
        echo '<div class="onesmtp-inline-form-grid">';
        echo '<p><label for="onesmtp-inline-from-email">' . esc_html__('From email', 'onesmtp') . '</label><input id="onesmtp-inline-from-email" class="regular-text" type="email" name="from_email" value="' . esc_attr((string) ($values['from_email'] ?? get_option('admin_email'))) . '" required></p>';
        echo '<p><label for="onesmtp-inline-from-name">' . esc_html__('From name', 'onesmtp') . '</label><input id="onesmtp-inline-from-name" class="regular-text" type="text" name="from_name" value="' . esc_attr((string) ($values['from_name'] ?? get_bloginfo('name'))) . '" required></p>';
        echo '</div><p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Save sender identity', 'onesmtp') . '</button></p></form></details>';
    }

    private function renderSenderIdentityMount(): void
    {
        $config = [
            'identity' => $this->senderIdentity->get()->toArray(),
            'endpoint' => rest_url('onesmtp/v1/settings/sender-identity'),
            'nonce' => wp_create_nonce('wp_rest'),
        ];

        echo '<div id="onesmtp-sender-identity" data-onesmtp-component="sender-identity-drawer" data-onesmtp-sender-identity-config="' . esc_attr((string) wp_json_encode($config)) . '"></div>';
    }

    private function isSenderIdentityReady(): bool
    {
        $values = $this->senderIdentity->get()->toArray();
        return trim((string) ($values['from_email'] ?? '')) !== '' && trim((string) ($values['from_name'] ?? '')) !== '';
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderTestForm(array $providers): void
    {
        $this->renderPanelOpen(
            __('Test email', 'onesmtp'),
            __('Use a live provider from this workspace to confirm outbound delivery before you leave setup.', 'onesmtp')
        );

        if (! $this->hasActiveProvider($providers)) {
            echo '<p>' . esc_html__('Add and activate a provider before sending a setup test email.', 'onesmtp') . '</p>';
            $this->renderPanelClose();

            return;
        }

        echo '<form method="post">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="send_test">';

        echo '<table class="form-table" role="presentation"><tbody>';
        $this->renderProviderSelect($providers);
        $this->renderTextInput('test_to', __('Recipient email', 'onesmtp'), (string) get_option('admin_email'), 'email');
        echo '</tbody></table>';
        submit_button(__('Send test email', 'onesmtp'));
        echo '</form>';
        $this->renderPanelClose();
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderCompletion(array $providers): void
    {
        echo '<div class="onesmtp-setup-confirmation">';
        echo '<h4>' . esc_html__('Confirmation', 'onesmtp') . '</h4><span class="screen-reader-text">' . esc_html__('Complete', 'onesmtp') . '</span>';

        if (! $this->hasActiveProvider($providers)) {
            echo '<p>' . esc_html__('Setup is incomplete. Add an active provider, then send a test email to finish the guided setup.', 'onesmtp') . '</p>';
            echo '</div>';

            return;
        }

        if ($this->activeProviderCount($providers) < 2) {
            echo '<p>' . esc_html__('A backup provider is recommended for failover. You can add one now or return later from Providers.', 'onesmtp') . '</p>';
        }

        echo '<p>' . esc_html__('Setup actions are written to the Aculect Mail event log so administrators can confirm the setup path is being recorded.', 'onesmtp') . '</p>';
        echo '</div>';
    }

    private function handleTestEmail(): void
    {
        $providerId = isset($_POST['provider_id']) ? absint(wp_unslash((string) $_POST['provider_id'])) : 0;
        $provider = $providerId > 0 ? $this->providers->find($providerId) : null;
        if (! is_array($provider)) {
            $this->redirect('failed');
        }

        $to = isset($_POST['test_to']) ? sanitize_email(wp_unslash((string) $_POST['test_to'])) : '';
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->redirect('invalid_recipient');
        }

        $result = $this->deliveryManager->send(
            $provider,
            [
                'to' => [$to],
                'subject' => '[Aculect Mail] Setup test email',
                'message' => 'This is a setup test email sent by Aculect Mail.',
                'headers' => [],
                'meta' => [
                    'source' => 'setup_wizard',
                ],
            ]
        );

        $this->events->add(
            'setup_test_email',
            [
                'ok' => $result->isSuccess(),
                'code' => $result->getCode(),
            ],
            null,
            (int) ($provider['id'] ?? $providerId)
        );

        $this->redirect($result->isSuccess() ? 'test_sent' : 'test_failed');
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizePostedProvider(): array
    {
        $adapterType = isset($_POST['adapter_type']) ? sanitize_key(wp_unslash((string) $_POST['adapter_type'])) : '';
        $fromEmail = isset($_POST['from_email']) ? sanitize_email(wp_unslash((string) $_POST['from_email'])) : '';
        $fromName = isset($_POST['from_name']) ? sanitize_text_field(wp_unslash((string) $_POST['from_name'])) : '';

        $config = [
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];

        foreach (['host', 'port', 'username', 'password', 'api_key'] as $field) {
            $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash((string) $_POST[$field])) : '';
            if ($value !== '') {
                $config[$field] = $value;
            }
        }

        return [
            'name' => isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '',
            'adapter_type' => ProviderTypes::isSupported($adapterType) ? $adapterType : '',
            'priority' => 100,
            'weight' => 1,
            'is_active' => 1,
            'config' => array_filter($config, static fn (string $value): bool => $value !== ''),
        ];
    }

    private function renderTextInput(string $name, string $label, string $value = '', string $type = 'text'): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($this->fieldId($name)) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="regular-text" id="' . esc_attr($this->fieldId($name)) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '">';
        echo '</td></tr>';
    }

    /**
     * @param array<string,mixed> $provider
     * @return array<int,string>
     */
    private function safeConfigFieldNames(array $provider): array
    {
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $fields = [];

        foreach ($config as $field => $value) {
            if (! is_scalar($value) || $value === '' || $this->isSensitiveConfigField((string) $field)) {
                continue;
            }

            $fields[] = sanitize_key((string) $field);
        }

        sort($fields);

        return array_values(array_unique($fields));
    }

    /**
     * @param array<string,mixed> $provider
     * @return array<int,string>
     */
    private function sensitiveConfigFieldNames(array $provider): array
    {
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $fields = [];

        foreach ($config as $field => $value) {
            if (! is_scalar($value) || $value === '' || ! $this->isSensitiveConfigField((string) $field)) {
                continue;
            }

            $fields[] = sanitize_key((string) $field);
        }

        sort($fields);

        return array_values(array_unique($fields));
    }

    private function isSensitiveConfigField(string $field): bool
    {
        return (bool) preg_match('/pass|secret|token|api(?:_|-)?key/i', $field);
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderProviderSelect(array $providers): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($this->fieldId('provider_id')) . '">' . esc_html__('Provider', 'onesmtp') . '</label></th><td>';
        echo '<select id="' . esc_attr($this->fieldId('provider_id')) . '" name="provider_id">';
        foreach ($providers as $provider) {
            if (empty($provider['is_active'])) {
                continue;
            }

            echo '<option value="' . esc_attr((string) ((int) ($provider['id'] ?? 0))) . '">' . esc_html((string) ($provider['name'] ?? '')) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function hasActiveProvider(array $providers): bool
    {
        return $this->activeProviderCount($providers) > 0;
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function activeProviderCount(array $providers): int
    {
        $count = 0;

        foreach ($providers as $provider) {
            if (! empty($provider['is_active'])) {
                $count++;
            }
        }

        return $count;
    }

    private function renderNotice(): void
    {
        $status = $this->latestStatus();
        if ($status === '') {
            return;
        }

        $messages = [
            'provider_saved' => __('Provider saved. Send a test email to verify setup.', 'onesmtp'),
            'test_sent' => __('Test email sent. Setup actions are being recorded in the event log.', 'onesmtp'),
            'test_failed' => __('Test email failed. Review provider credentials and try again.', 'onesmtp'),
            'invalid_recipient' => __('Enter a valid recipient email address for the test email.', 'onesmtp'),
            'failed' => __('Setup action failed.', 'onesmtp'),
        ];

        $message = $messages[$status] ?? '';
        if ($message === '') {
            return;
        }

        $class = in_array($status, ['provider_saved', 'test_sent'], true) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function renderPanelOpen(string $title, string $description, bool $fullWidth = false): void
    {
        $classes = 'onesmtp-setup-panel postbox';
        if ($fullWidth) {
            $classes .= ' onesmtp-setup-panel--full';
        }

        echo '<section class="' . esc_attr($classes) . '">';
        echo '<div class="postbox-header">';
        echo '<h3 class="hndle">' . esc_html($title) . '</h3>';
        echo '</div>';
        echo '<div class="inside">';
        echo '<p class="onesmtp-setup-panel-description">' . esc_html($description) . '</p>';
    }

    private function renderPanelClose(): void
    {
        echo '</div></section>';
    }

    private function latestStatus(): string
    {
        return isset($_GET['onesmtp_setup_status']) ? sanitize_key(wp_unslash((string) $_GET['onesmtp_setup_status'])) : '';
    }

    private function redirect(string $status): void
    {
        $url = add_query_arg(
            ['onesmtp_setup_status' => $status],
            admin_url('options-general.php?page=onesmtp#onesmtp-setup')
        );

        wp_safe_redirect($url);
        if (defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING')) {
            throw new \RuntimeException('Aculect Mail setup wizard redirected.');
        }

        exit;
    }

    private function fieldId(string $name): string
    {
        return 'onesmtp-setup-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower($name));
    }
}
