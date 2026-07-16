<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Core\Capabilities;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\ProviderRepository;

final class SetupWizard
{
    private const ACTION_NAME = 'onesmtp_setup_action';
    private const NONCE_NAME  = 'onesmtp_setup_nonce';

    private ProviderRepository $providers;
    private ProviderDeliveryManager $deliveryManager;
    private EventRepository $events;
    private AdminAuditLogger $auditLogger;

    public function __construct(
        ?ProviderRepository $providers = null,
        ?ProviderDeliveryManager $deliveryManager = null,
        ?EventRepository $events = null,
        ?AdminAuditLogger $auditLogger = null
    ) {
        $this->providers       = $providers ?? new ProviderRepository();
        $this->deliveryManager = $deliveryManager ?? new ProviderDeliveryManager();
        $this->events          = $events ?? new EventRepository();
        $this->auditLogger     = $auditLogger ?? new AdminAuditLogger();
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
                esc_html__('You do not have permission to run the OneSMTP setup wizard.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
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

        echo '<div class="onesmtp-setup-shell">';
        echo '<div class="onesmtp-setup-main">';
        echo '<div class="onesmtp-setup-notices">';
        $this->renderNotice();
        echo '</div>';

        $this->renderPanelOpen(
            __('Guided setup', 'onesmtp'),
            __('Use this guided setup to configure a sender identity, add the first delivery provider, verify delivery, and confirm logging is recording setup activity.')
        );
        $this->renderProgress($providers);
        $this->renderPanelClose();

        $this->renderPanelOpen(
            __('Provider capability matrix', 'onesmtp'),
            __('Review provider delivery features before choosing the first provider. The matrix is based on current adapter metadata and keeps unsupported capabilities visible without blocking setup.')
        );
        (new ProviderCapabilityMatrix())->render();
        $this->renderPanelClose();

        $this->renderProviderForm($providers);
        $this->renderTestForm($providers);
        $this->renderCompletion($providers);
        echo '</div>';

        echo '<aside class="onesmtp-setup-rail" aria-label="' . esc_attr__('Setup guidance', 'onesmtp') . '">';
        $this->renderRailCard(
            __('Current state', 'onesmtp'),
            $this->setupStateSummary($providers),
            __('Track whether a provider is active and whether the test email step is still pending.')
        );
        $this->renderRailCard(
            __('What stays private', 'onesmtp'),
            __('Secrets remain hidden', 'onesmtp'),
            __('Passwords, tokens, and raw provider payloads are never printed back in this workspace.')
        );
        $this->renderRailCard(
            __('Next action', 'onesmtp'),
            $this->hasActiveProvider($providers) ? __('Send the setup test email', 'onesmtp') : __('Save the first provider', 'onesmtp'),
            __('Complete the remaining step, then return to Providers if you want a backup or failover provider.'),
            $this->hasActiveProvider($providers) ? __('Send test email', 'onesmtp') : __('Open Providers', 'onesmtp'),
            $this->hasActiveProvider($providers) ? '#onesmtp-setup' : '#onesmtp-providers'
        );
        echo '</aside>';
        echo '</div>';
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderProgress(array $providers): void
    {
        $hasProvider = $this->hasActiveProvider($providers);

        echo '<ol class="onesmtp-setup-summary-list">';
        echo '<li>' . esc_html__('Sender identity and first provider', 'onesmtp') . ': <strong>' . esc_html($hasProvider ? __('Complete', 'onesmtp') : __('Needs setup', 'onesmtp')) . '</strong></li>';
        echo '<li>' . esc_html__('Backup provider prompt', 'onesmtp') . ': <strong>' . esc_html(count($providers) > 1 ? __('Complete', 'onesmtp') : __('Recommended next', 'onesmtp')) . '</strong></li>';
        echo '<li>' . esc_html__('Test email verification', 'onesmtp') . ': <strong>' . esc_html($this->latestStatus() === 'test_sent' ? __('Complete', 'onesmtp') : __('Pending', 'onesmtp')) . '</strong></li>';
        echo '<li>' . esc_html__('Setup log confirmation', 'onesmtp') . ': <strong>' . esc_html($hasProvider ? __('Recording setup events', 'onesmtp') : __('Pending provider save', 'onesmtp')) . '</strong></li>';
        echo '</ol>';

        if (! $hasProvider) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Complete the first provider form to enable delivery testing.', 'onesmtp') . '</p></div>';
        }
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderProviderForm(array $providers): void
    {
        $this->renderPanelOpen(
            __('First provider', 'onesmtp'),
            __('Choose the delivery provider that will carry normal WordPress mail after setup is complete.')
        );
        echo '<form method="post">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="save_provider">';

        echo '<table class="form-table" role="presentation"><tbody>';
        $this->renderTextInput('from_email', __('Sender email', 'onesmtp'), (string) get_option('admin_email'), 'email');
        $this->renderTextInput('from_name', __('Sender name', 'onesmtp'), (string) get_bloginfo('name'));
        $this->renderTextInput('name', __('Provider name', 'onesmtp'), __('Primary delivery provider', 'onesmtp'));
        $this->renderSelectInput('adapter_type', __('Provider type', 'onesmtp'), ProviderTypes::all());
        $this->renderTextInput('host', __('SMTP host', 'onesmtp'));
        $this->renderTextInput('port', __('SMTP port', 'onesmtp'), '', 'number');
        $this->renderTextInput('username', __('Username', 'onesmtp'));
        $this->renderTextInput('password', __('Password', 'onesmtp'), '', 'password');
        $this->renderTextInput('api_key', __('API key', 'onesmtp'), '', 'password');
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Secrets are stored through the provider repository and are not printed back in the wizard.', 'onesmtp') . '</p>';
        submit_button($providers === [] ? __('Save first provider', 'onesmtp') : __('Save another provider', 'onesmtp'));
        echo '</form>';
        $this->renderPanelClose();
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderTestForm(array $providers): void
    {
        $this->renderPanelOpen(
            __('Test email', 'onesmtp'),
            __('Use a live provider from this workspace to confirm outbound delivery before you leave setup.')
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
        $this->renderPanelOpen(
            __('Confirmation', 'onesmtp'),
            __('Finish setup by confirming the setup path is visible in the event log and by adding a backup provider when you are ready.')
        );

        if (! $this->hasActiveProvider($providers)) {
            echo '<p>' . esc_html__('Setup is incomplete. Add an active provider, then send a test email to finish the guided setup.', 'onesmtp') . '</p>';
            $this->renderPanelClose();

            return;
        }

        if (count($providers) < 2) {
            echo '<p>' . esc_html__('A backup provider is recommended for failover. You can add one now or return later from Providers.', 'onesmtp') . '</p>';
        }

        echo '<p>' . esc_html__('Setup actions are written to the OneSMTP event log so administrators can confirm the setup path is being recorded.', 'onesmtp') . '</p>';
        $this->renderPanelClose();
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
                'subject' => '[OneSMTP] Setup test email',
                'message' => 'This is a setup test email sent by OneSMTP.',
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
     * @param array<int,string> $options Options.
     */
    private function renderSelectInput(string $name, string $label, array $options): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($this->fieldId($name)) . '">' . esc_html($label) . '</label></th><td>';
        echo '<select id="' . esc_attr($this->fieldId($name)) . '" name="' . esc_attr($name) . '">';
        foreach ($options as $option) {
            echo '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
        }
        echo '</select>';
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
        foreach ($providers as $provider) {
            if (! empty($provider['is_active'])) {
                return true;
            }
        }

        return false;
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

    private function renderRailCard(string $title, string $value, string $description, string $linkLabel = '', string $linkTarget = ''): void
    {
        echo '<section class="onesmtp-context-card">';
        echo '<div class="onesmtp-context-card-copy">';
        echo '<p class="onesmtp-context-card-title">' . esc_html($title) . '</p>';
        echo '<p class="onesmtp-context-card-value">' . esc_html($value) . '</p>';
        echo '<p class="onesmtp-context-card-description">' . esc_html($description) . '</p>';
        echo '</div>';

        if ($linkLabel !== '' && $linkTarget !== '') {
            echo '<a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=onesmtp' . $linkTarget)) . '">' . esc_html($linkLabel) . '</a>';
        }

        echo '</section>';
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function setupStateSummary(array $providers): string
    {
        $providerCount = count($providers);
        $providerLabel = $providerCount === 1
            ? __('1 active provider', 'onesmtp')
            : sprintf(
                /* translators: %d: active provider count. */
                __('%d active providers', 'onesmtp'),
                $providerCount
            );

        if (! $this->hasActiveProvider($providers)) {
            return __('No active provider yet. Save the first provider to unlock test email verification.', 'onesmtp');
        }

        if ($this->latestStatus() === 'test_sent') {
            return sprintf(
                /* translators: 1: provider summary, 2: setup completion string. */
                __('%1$s and setup verification is complete.', 'onesmtp'),
                $providerLabel
            );
        }

        return sprintf(
            /* translators: 1: provider summary, 2: setup completion string. */
            __('%1$s and the test email step is still pending.', 'onesmtp'),
            $providerLabel
        );
    }

    private function latestStatus(): string
    {
        return isset($_GET['onesmtp_setup_status']) ? sanitize_key(wp_unslash((string) $_GET['onesmtp_setup_status'])) : '';
    }

    private function redirect(string $status): void
    {
        $url = add_query_arg(
            ['onesmtp_setup_status' => $status],
            admin_url('admin.php?page=onesmtp#onesmtp-setup')
        );

        wp_safe_redirect($url);
        if (defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING')) {
            throw new \RuntimeException('OneSMTP setup wizard redirected.');
        }

        exit;
    }

    private function fieldId(string $name): string
    {
        return 'onesmtp-setup-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower($name));
    }
}
