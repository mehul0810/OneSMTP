<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Core\Capabilities;
use OneSMTP\Dns\DomainAuthenticationChecker;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Settings\SenderIdentityRepository;

final class ProviderAdmin
{
    private const ACTION_NAME = 'onesmtp_provider_action';
    private const NONCE_NAME  = 'onesmtp_provider_nonce';

    private ProviderRepository $repository;
    private DomainAuthenticationChecker $dnsAuthentication;
    private SenderIdentityRepository $senderIdentity;
    private AdminAuditLogger $auditLogger;

    public function __construct(ProviderRepository $repository, ?DomainAuthenticationChecker $dnsAuthentication = null, ?SenderIdentityRepository $senderIdentity = null, ?AdminAuditLogger $auditLogger = null)
    {
        $this->repository = $repository;
        $this->dnsAuthentication = $dnsAuthentication ?? new DomainAuthenticationChecker();
        $this->senderIdentity = $senderIdentity ?? new SenderIdentityRepository();
        $this->auditLogger = $auditLogger ?? new AdminAuditLogger();
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
            $provider = $this->normalizePostedProvider();
            $providerId = $this->repository->save($provider);
            if ($providerId > 0) {
                $safeProvider = $this->repository->findSafe($providerId) ?? [];
                $this->auditLogger->logProviderChange(
                    ! empty($provider['id']) ? 'updated' : 'created',
                    $providerId,
                    [
                        'source' => 'providers_admin',
                        'provider_name' => (string) ($safeProvider['name'] ?? $provider['name'] ?? ''),
                        'adapter_type' => (string) ($safeProvider['adapter_type'] ?? $provider['adapter_type'] ?? ''),
                        'is_active' => ! empty($safeProvider['is_active'] ?? $provider['is_active'] ?? false),
                        'safe_config_fields' => $this->safeConfigFieldNames($provider),
                        'credential_fields_updated' => $this->sensitiveConfigFieldNames($provider),
                    ]
                );
            }
            $this->redirect($providerId > 0 ? 'saved' : 'failed');
        }

        if ($action === 'delete') {
            $providerId = isset($_POST['provider_id']) ? absint(wp_unslash((string) $_POST['provider_id'])) : 0;
            $deleted = $providerId > 0 && $this->repository->delete($providerId);
            if ($deleted) {
                $this->auditLogger->logProviderChange('deleted', $providerId, ['source' => 'providers_admin']);
            }
            $this->redirect($deleted ? 'deleted' : 'failed');
        }

        if ($action === 'toggle') {
            $providerId = isset($_POST['provider_id']) ? absint(wp_unslash((string) $_POST['provider_id'])) : 0;
            $provider = $providerId > 0 ? $this->repository->find($providerId) : null;
            if (is_array($provider)) {
                $provider['is_active'] = empty($provider['is_active']) ? 1 : 0;
                $this->repository->save($provider);
                $this->auditLogger->logProviderChange(
                    ! empty($provider['is_active']) ? 'activated' : 'deactivated',
                    $providerId,
                    [
                        'source' => 'providers_admin',
                        'provider_name' => (string) ($provider['name'] ?? ''),
                        'adapter_type' => (string) ($provider['adapter_type'] ?? ''),
                        'is_active' => ! empty($provider['is_active']),
                    ]
                );
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
        $this->renderDnsAuthentication($providers);
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
        echo '<th scope="col">' . esc_html__('Name', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Type', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Priority', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Weight', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Status', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Health', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Safe config', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Actions', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($providers as $provider) {
            $providerId = (int) ($provider['id'] ?? 0);
            $providerName = trim((string) ($provider['name'] ?? ''));
            if ($providerName === '') {
                $providerName = sprintf(
                    /* translators: %d: provider id. */
                    __('Provider #%d', 'onesmtp'),
                    $providerId
                );
            }

            echo '<tr>';
            echo '<th scope="row">' . esc_html((string) ($provider['name'] ?? '')) . '<br><code>' . esc_html((string) ($provider['slug'] ?? '')) . '</code></th>';
            echo '<td><code>' . esc_html((string) ($provider['adapter_type'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ((int) ($provider['priority'] ?? 100))) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($provider['weight'] ?? 1))) . '</td>';
            echo '<td>';
            echo empty($provider['is_active']) ? esc_html__('Inactive', 'onesmtp') : esc_html__('Active', 'onesmtp');
            if ($providerId > 0 && $this->repository->hasCredentialRecoveryRequired($providerId)) {
                echo '<br><span class="description">' . esc_html__('Credential recovery required. Re-enter affected credentials to restore delivery.', 'onesmtp') . '</span>';
            }
            echo '</td>';
            echo '<td>' . $this->formatCircuitHealth($provider) . '</td>';
            echo '<td style="max-width:32em;white-space:normal;word-break:break-word;">' . esc_html($this->formatSafeConfig(isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [])) . '</td>';
            echo '<td>';
            $this->renderRowActionForm($providerId, 'toggle', empty($provider['is_active']) ? __('Activate', 'onesmtp') : __('Deactivate', 'onesmtp'), $providerName);
            $this->renderRowActionForm($providerId, 'delete', __('Delete', 'onesmtp'), $providerName);
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function renderRowActionForm(int $providerId, string $action, string $label, string $providerName): void
    {
        echo '<form method="post" style="display:inline-block;margin-right:6px">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="provider_id" value="' . esc_attr((string) $providerId) . '">';
        submit_button(
            $label,
            'secondary small',
            'submit',
            false,
            [
                'aria-label' => sprintf(
                    /* translators: 1: provider action, 2: provider name. */
                    __('%1$s provider %2$s', 'onesmtp'),
                    $label,
                    $providerName
                ),
            ]
        );
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
            'dkim_selector' => __('DKIM selector', 'onesmtp'),
        ];
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
        return (bool) preg_match('/pass|secret|token|api(?:_|-)?key|client_id/i', $field);
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     */
    private function renderDnsAuthentication(array $providers): void
    {
        echo '<h3>' . esc_html__('DNS authentication readiness', 'onesmtp') . '</h3>';

        $domains = $this->configuredSenderDomains($providers);
        if ($domains === []) {
            echo '<p>' . esc_html__('Configure a provider From email or sender identity From Email to see SPF, DKIM, and DMARC guidance for sender domains.', 'onesmtp') . '</p>';

            return;
        }

        if (! $this->dnsAuthentication->lookupAvailable()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('DNS TXT lookup is not available in this PHP environment. OneSMTP can show expected records, but it cannot verify SPF, DKIM, or DMARC readiness here.', 'onesmtp') . '</p></div>';
        }

        echo '<p>' . esc_html__('Review sender-domain authentication before relying on a provider for production delivery. Results are based only on TXT evidence visible to this WordPress server.', 'onesmtp') . '</p>';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Domain', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Source', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('SPF', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('DKIM', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('DMARC', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Guidance', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($domains as $domain => $context) {
            $check = $this->dnsAuthentication->check($domain, (string) ($context['selector'] ?? ''));
            echo '<tr>';
            echo '<th scope="row"><code>' . esc_html((string) $check['domain']) . '</code></th>';
            echo '<td>' . esc_html(implode(', ', $context['sources'])) . '</td>';
            echo '<td>' . esc_html($this->dnsStatusLabel($check['spf']['status'])) . '<br><code>' . esc_html($check['spf']['query']) . '</code></td>';
            echo '<td>' . esc_html($this->dnsStatusLabel($check['dkim']['status'])) . '<br>' . ($check['dkim']['query'] !== '' ? '<code>' . esc_html($check['dkim']['query']) . '</code>' : '<span class="description">' . esc_html__('Add a DKIM selector to enable selector-specific checks.', 'onesmtp') . '</span>') . '</td>';
            echo '<td>' . esc_html($this->dnsStatusLabel($check['dmarc']['status'])) . '<br><code>' . esc_html($check['dmarc']['query']) . '</code></td>';
            echo '<td>' . esc_html($this->dnsGuidance((string) ($context['provider_type'] ?? ''), (string) ($context['selector'] ?? ''))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<int,array<string,mixed>> $providers Providers.
     * @return array<string,array{sources:array<int,string>,selector:string,provider_type:string}>
     */
    private function configuredSenderDomains(array $providers): array
    {
        $domains = [];

        foreach ($providers as $provider) {
            $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
            $fromEmail = isset($config['from_email']) ? (string) $config['from_email'] : '';
            $domain = $this->domainFromEmail($fromEmail);
            if ($domain === '') {
                continue;
            }

            if (! isset($domains[$domain])) {
                $domains[$domain] = [
                    'sources' => [],
                    'selector' => '',
                    'provider_type' => '',
                ];
            }

            $providerName = trim((string) ($provider['name'] ?? ''));
            $source = $providerName !== '' ? $providerName : __('Provider sender', 'onesmtp');
            $domains[$domain]['sources'][] = $source;
            $domains[$domain]['selector'] = isset($config['dkim_selector']) ? (string) $config['dkim_selector'] : '';
            $domains[$domain]['provider_type'] = (string) ($provider['adapter_type'] ?? '');
        }

        $identityDomain = $this->domainFromEmail($this->senderIdentity->get()->getFromEmail());
        if ($identityDomain !== '') {
            if (! isset($domains[$identityDomain])) {
                $domains[$identityDomain] = [
                    'sources' => [],
                    'selector' => '',
                    'provider_type' => '',
                ];
            }

            $domains[$identityDomain]['sources'][] = __('Sender identity', 'onesmtp');
        }

        foreach ($domains as $domain => $context) {
            $sources = array_values(array_unique(array_map('strval', $context['sources'])));
            sort($sources);
            $domains[$domain] = [
                'sources' => $sources,
                'selector' => (string) $context['selector'],
                'provider_type' => (string) $context['provider_type'],
            ];
        }

        ksort($domains);

        return $domains;
    }

    private function domainFromEmail(string $email): string
    {
        $email = sanitize_email($email);
        if ($email === '' || ! str_contains($email, '@')) {
            return '';
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain) ?? '';

        return trim($domain, '.');
    }

    private function dnsStatusLabel(string $status): string
    {
        return match ($status) {
            DomainAuthenticationChecker::STATUS_PRESENT => __('TXT evidence found', 'onesmtp'),
            DomainAuthenticationChecker::STATUS_MISSING => __('No matching TXT evidence found', 'onesmtp'),
            default => __('Inconclusive', 'onesmtp'),
        };
    }

    private function dnsGuidance(string $providerType, string $selector): string
    {
        $provider = match ($providerType) {
            'sendgrid' => __('SendGrid', 'onesmtp'),
            'postmark' => __('Postmark', 'onesmtp'),
            'brevo' => __('Brevo', 'onesmtp'),
            'gmail' => __('Gmail or Google Workspace', 'onesmtp'),
            'smtp' => __('your SMTP provider', 'onesmtp'),
            'php_mail' => __('your hosting DNS provider', 'onesmtp'),
            default => __('your mail provider', 'onesmtp'),
        };

        $message = sprintf(
            /* translators: %s: provider name. */
            __('Publish the SPF, DKIM, and DMARC TXT records supplied by %s for this sender domain.', 'onesmtp'),
            $provider
        );

        if ($selector === '') {
            $message .= ' ' . __('Add the provider DKIM selector when available so OneSMTP can check the selector-specific TXT name.', 'onesmtp');
        }

        return $message;
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
            'priority' => $this->normalizePostedPositiveInteger('priority', 100),
            'weight' => $this->normalizePostedPositiveInteger('weight', 1),
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

    private function normalizePostedPositiveInteger(string $field, int $default): int
    {
        if (! isset($_POST[$field])) {
            return $default;
        }

        $value = trim(wp_unslash((string) $_POST[$field]));
        if ($value === '' || ! ctype_digit($value)) {
            return 1;
        }

        return max(1, absint($value));
    }

    /**
     * @param array<string,mixed> $provider Provider.
     */
    private function formatCircuitHealth(array $provider): string
    {
        $state = (string) ($provider['circuit_state'] ?? 'closed');
        if ($state !== 'open') {
            return esc_html__('Circuit closed', 'onesmtp');
        }

        $until = isset($provider['circuit_until']) ? trim((string) $provider['circuit_until']) : '';
        if ($until === '') {
            return esc_html__('Circuit open', 'onesmtp') . '<br><span class="description">' . esc_html__('Open until manually closed.', 'onesmtp') . '</span>';
        }

        return esc_html__('Circuit open', 'onesmtp') . '<br><span class="description">' . sprintf(
            /* translators: %s: GMT circuit open-until date/time. */
            esc_html__('Open until %s GMT.', 'onesmtp'),
            esc_html($until)
        ) . '</span>';
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
