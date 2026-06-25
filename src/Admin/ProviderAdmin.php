<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\ProviderRepository;

final class ProviderAdmin
{
    private const ACTION_NAME = 'onesmtp_provider_action';
    private const NONCE_NAME  = 'onesmtp_provider_nonce';

    private ProviderRepository $repository;

    public function __construct(ProviderRepository $repository)
    {
        $this->repository = $repository;
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
                esc_html__('You do not have permission to manage OneSMTP providers.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION_NAME, self::NONCE_NAME);

        if ($action === 'save') {
            $providerId = $this->repository->save($this->normalizePostedProvider());
            $this->redirect($providerId > 0 ? 'saved' : 'failed');
        }

        if ($action === 'delete') {
            $providerId = isset($_POST['provider_id']) ? absint(wp_unslash((string) $_POST['provider_id'])) : 0;
            $deleted = $providerId > 0 && $this->repository->delete($providerId);
            $this->redirect($deleted ? 'deleted' : 'failed');
        }

        if ($action === 'toggle') {
            $providerId = isset($_POST['provider_id']) ? absint(wp_unslash((string) $_POST['provider_id'])) : 0;
            $provider = $providerId > 0 ? $this->repository->find($providerId) : null;
            if (is_array($provider)) {
                $provider['is_active'] = empty($provider['is_active']) ? 1 : 0;
                $this->repository->save($provider);
                $this->redirect('saved');
            }

            $this->redirect('failed');
        }
    }

    public function render(): void
    {
        $providers = $this->repository->getAllSafe();

        $this->renderNotice();
        echo '<p>' . esc_html__('Create and manage the delivery providers OneSMTP can use for failover and rotation.', 'onesmtp') . '</p>';
        (new ProviderCapabilityMatrix())->render();
        $this->renderProviderTable($providers);
        $this->renderForm();
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderProviderTable(array $providers): void
    {
        echo '<h3>' . esc_html__('Configured providers', 'onesmtp') . '</h3>';

        if ($providers === []) {
            echo '<p>' . esc_html__('No providers are configured yet.', 'onesmtp') . '</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Type', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Priority', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Weight', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Status', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Safe config', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Actions', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($providers as $provider) {
            $providerId = (int) ($provider['id'] ?? 0);

            echo '<tr>';
            echo '<td>' . esc_html((string) ($provider['name'] ?? '')) . '<br><code>' . esc_html((string) ($provider['slug'] ?? '')) . '</code></td>';
            echo '<td><code>' . esc_html((string) ($provider['adapter_type'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ((int) ($provider['priority'] ?? 100))) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($provider['weight'] ?? 1))) . '</td>';
            echo '<td>';
            echo empty($provider['is_active']) ? esc_html__('Inactive', 'onesmtp') : esc_html__('Active', 'onesmtp');
            if ($providerId > 0 && $this->repository->hasCredentialRecoveryRequired($providerId)) {
                echo '<br><span class="description">' . esc_html__('Credential recovery required. Re-enter affected credentials to restore delivery.', 'onesmtp') . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html($this->formatSafeConfig(isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [])) . '</td>';
            echo '<td>';
            $this->renderRowActionForm($providerId, 'toggle', empty($provider['is_active']) ? __('Activate', 'onesmtp') : __('Deactivate', 'onesmtp'));
            $this->renderRowActionForm($providerId, 'delete', __('Delete', 'onesmtp'));
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function renderRowActionForm(int $providerId, string $action, string $label): void
    {
        echo '<form method="post" style="display:inline-block;margin-right:6px">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="provider_id" value="' . esc_attr((string) $providerId) . '">';
        submit_button($label, 'secondary small', 'submit', false);
        echo '</form>';
    }

    private function renderForm(): void
    {
        echo '<h3>' . esc_html__('Add or update provider', 'onesmtp') . '</h3>';
        echo '<form method="post" class="onesmtp-provider-form">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="save">';

        echo '<table class="form-table" role="presentation"><tbody>';
        $this->renderTextInput('provider_id', __('Provider ID for updates', 'onesmtp'), '', 'number');
        $this->renderTextInput('name', __('Name', 'onesmtp'));
        $this->renderSelectInput('adapter_type', __('Provider type', 'onesmtp'), ProviderTypes::all());
        $this->renderTextInput('priority', __('Priority', 'onesmtp'), '100', 'number');
        $this->renderTextInput('weight', __('Weight', 'onesmtp'), '1', 'number');

        echo '<tr><th scope="row">' . esc_html__('Active', 'onesmtp') . '</th><td><label>';
        echo '<input type="checkbox" name="is_active" value="1"> ' . esc_html__('Use this provider for delivery.', 'onesmtp');
        echo '</label></td></tr>';

        foreach ($this->configFields() as $field => $label) {
            $type = str_contains($field, 'password') || str_contains($field, 'secret') || str_contains($field, 'token') || str_contains($field, 'api_key') ? 'password' : 'text';
            $this->renderTextInput('config[' . $field . ']', $label, '', $type);
        }

        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Leave credential fields blank when updating a provider to keep existing stored secrets.', 'onesmtp') . '</p>';
        submit_button(__('Save provider', 'onesmtp'));
        echo '</form>';
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
     * @return array<string,string>
     */
    private function configFields(): array
    {
        return [
            'host' => __('SMTP host', 'onesmtp'),
            'port' => __('SMTP port', 'onesmtp'),
            'username' => __('Username', 'onesmtp'),
            'password' => __('Password', 'onesmtp'),
            'api_key' => __('API key', 'onesmtp'),
            'client_id' => __('OAuth client ID', 'onesmtp'),
            'client_secret' => __('OAuth client secret', 'onesmtp'),
            'refresh_token' => __('OAuth refresh token', 'onesmtp'),
            'from_email' => __('From email', 'onesmtp'),
            'from_name' => __('From name', 'onesmtp'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizePostedProvider(): array
    {
        $providerId = isset($_POST['provider_id']) ? absint(wp_unslash((string) $_POST['provider_id'])) : 0;
        $adapterType = isset($_POST['adapter_type']) ? sanitize_key(wp_unslash((string) $_POST['adapter_type'])) : '';
        $existing = $providerId > 0 ? $this->repository->find($providerId) : null;

        $payload = [
            'name' => isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '',
            'adapter_type' => ProviderTypes::isSupported($adapterType) ? $adapterType : '',
            'priority' => isset($_POST['priority']) ? max(1, absint(wp_unslash((string) $_POST['priority']))) : 100,
            'weight' => isset($_POST['weight']) ? max(1, absint(wp_unslash((string) $_POST['weight']))) : 1,
            'is_active' => ! empty($_POST['is_active']) ? 1 : 0,
            'config' => $this->normalizePostedConfig($providerId),
        ];

        if ($providerId > 0) {
            $payload['id'] = $providerId;
            $payload['slug'] = is_array($existing) ? sanitize_key((string) ($existing['slug'] ?? '')) : '';
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizePostedConfig(int $providerId): array
    {
        $existing = $providerId > 0 ? $this->repository->find($providerId) : null;
        $config = is_array($existing) && isset($existing['config']) && is_array($existing['config']) ? $existing['config'] : [];
        $posted = isset($_POST['config']) && is_array($_POST['config']) ? wp_unslash($_POST['config']) : [];

        foreach ($this->configFields() as $field => $label) {
            if (! array_key_exists($field, $posted)) {
                continue;
            }

            $value = sanitize_text_field((string) $posted[$field]);
            if ($value === '' && $this->isSensitiveField($field)) {
                continue;
            }

            if ($value === '') {
                unset($config[$field]);
                continue;
            }

            $config[$field] = $value;
        }

        return $config;
    }

    private function isSensitiveField(string $field): bool
    {
        return (bool) preg_match('/pass|secret|token|api(?:_|-)?key/i', $field);
    }

    /**
     * @param array<string,mixed> $config Config.
     */
    private function formatSafeConfig(array $config): string
    {
        if ($config === []) {
            return __('No config stored', 'onesmtp');
        }

        $parts = [];
        foreach ($config as $key => $value) {
            $parts[] = $key . ': ' . (is_scalar($value) ? (string) $value : '[complex]');
        }

        return implode(', ', $parts);
    }

    private function renderNotice(): void
    {
        $status = isset($_GET['onesmtp_provider_status']) ? sanitize_key(wp_unslash((string) $_GET['onesmtp_provider_status'])) : '';
        if ($status === '') {
            return;
        }

        $message = $status === 'failed'
            ? __('Provider action failed.', 'onesmtp')
            : __('Provider action completed.', 'onesmtp');

        echo '<div class="notice ' . ($status === 'failed' ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function redirect(string $status): void
    {
        $url = add_query_arg(
            ['onesmtp_provider_status' => $status],
            admin_url('admin.php?page=onesmtp#onesmtp-providers')
        );

        wp_safe_redirect($url);
        if (defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING')) {
            throw new \RuntimeException('OneSMTP provider admin redirected.');
        }

        exit;
    }

    private function fieldId(string $name): string
    {
        return 'onesmtp-provider-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower($name));
    }
}
