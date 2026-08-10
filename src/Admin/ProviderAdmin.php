<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Core\Capabilities;
use OneSMTP\Dns\DomainAuthenticationChecker;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Migration\SureMailMigrationService;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Quota\ProviderQuotaSettings;
use OneSMTP\Quota\ProviderQuotaSettingsKey;
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
    private AdminRequest $request;
    private SureMailMigrationService $sureMailMigration;
    private FeatureGate $featureGate;

    public function __construct(ProviderRepository $repository, ?DomainAuthenticationChecker $dnsAuthentication = null, ?SenderIdentityRepository $senderIdentity = null, ?AdminAuditLogger $auditLogger = null, ?AdminRequest $request = null, ?SureMailMigrationService $sureMailMigration = null, ?FeatureGate $featureGate = null)
    {
        $this->repository = $repository;
        $this->dnsAuthentication = $dnsAuthentication ?? new DomainAuthenticationChecker();
        $this->senderIdentity = $senderIdentity ?? new SenderIdentityRepository();
        $this->auditLogger = $auditLogger ?? new AdminAuditLogger();
        $this->request = $request ?? new AdminRequest();
        $this->sureMailMigration = $sureMailMigration ?? new SureMailMigrationService($repository);
        $this->featureGate = $featureGate ?? new FeatureGate();
    }

    public function handleRequest(): void
    {
        if ($this->request->method() !== 'POST') {
            return;
        }

        $action = $this->request->postAction(self::ACTION_NAME);
        if ($action === '') {
            return;
        }

        if (! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to manage Aculect Mail providers.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION_NAME, self::NONCE_NAME);

        if ($action === 'suremail_analyze') {
            $analysis = $this->sureMailMigration->analyze();
            set_transient($this->sureMailAnalysisKey(), $analysis, 15 * 60);
            $this->redirect('suremail_analyzed');
        }

        if ($action === 'suremail_import') {
            $analysis = get_transient($this->sureMailAnalysisKey());
            $fingerprint = isset($_POST['suremail_fingerprint']) ? sanitize_text_field(wp_unslash((string) $_POST['suremail_fingerprint'])) : '';
            if (! is_array($analysis) || $fingerprint === '' || ! hash_equals((string) ($analysis['fingerprint'] ?? ''), $fingerprint)) {
                $this->redirect('suremail_analysis_expired');
            }
            $result = $this->sureMailMigration->import($fingerprint);
            delete_transient($this->sureMailAnalysisKey());
            $this->redirect($result['ok'] ? 'suremail_imported' : 'suremail_import_failed');
        }

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
                        'quota_enabled' => $this->featureGate->isEnabled(FeatureGate::PROVIDER_QUOTA_BUDGETS),
                        'quota_limits' => $this->quotaAuditLimits($safeProvider !== [] ? $safeProvider : $provider),
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
        $this->renderSureMailCompatibility();
        $this->renderProviderCatalog($providers);
        echo '<details id="onesmtp-provider-form" class="onesmtp-provider-form"><summary>' . esc_html__('Add provider', 'onesmtp') . '</summary>';
        $this->renderForm();
        echo '</details>';
    }

    /**
     * Render provider controls that are useful to administrators configuring
     * multi-provider delivery, but would distract from the connection catalog.
     */
    public function renderAdvancedTools(): void
    {
        $providers = $this->repository->getAllSafe();

        echo '<div class="onesmtp-settings-grid onesmtp-provider-advanced-tools">';
        echo '<section class="onesmtp-settings-panel postbox"><div class="postbox-header"><h3 class="hndle">' . esc_html__('Provider capabilities', 'onesmtp') . '</h3></div><div class="inside">';
        echo '<p class="description onesmtp-settings-panel-description">' . esc_html__('Compare delivery features before assigning primary and backup providers.', 'onesmtp') . '</p>';
        (new ProviderCapabilityMatrix())->render();
        echo '</div></section>';
        echo '<section class="onesmtp-settings-panel postbox"><div class="postbox-header"><h3 class="hndle">' . esc_html__('Domain authentication', 'onesmtp') . '</h3></div><div class="inside">';
        echo '<p class="description onesmtp-settings-panel-description">' . esc_html__('Review the authentication status for your configured sending domains.', 'onesmtp') . '</p>';
        $this->renderDnsAuthentication($providers);
        echo '</div></section>';
        echo '</div>';
    }

    /** @param array<int,array<string,mixed>> $providers */
    private function renderProviderCatalog(array $providers): void
    {
        $providersByType = [];
        foreach ($providers as $provider) {
            $type = sanitize_key((string) ($provider['adapter_type'] ?? ''));
            if ($type !== '') {
                $providersByType[$type][] = $provider;
            }
        }

        echo '<section class="onesmtp-provider-catalog" aria-label="' . esc_attr__('Email providers', 'onesmtp') . '">';
        echo '<div class="onesmtp-provider-list">';

        foreach (ProviderTypes::metadata() as $type => $metadata) {
            $connections = $providersByType[$type] ?? [];
            $activeCount = count(array_filter($connections, static fn (array $provider): bool => ! empty($provider['is_active'])));
            $connectionCount = count($connections);
            $statusClass = $activeCount > 0 ? 'is-active' : ($connectionCount > 0 ? 'is-inactive' : 'is-disconnected');
            $statusLabel = $activeCount === 1
                ? __('1 active connection', 'onesmtp')
                : ($activeCount > 1
                    ? sprintf(
                        /* translators: %s: number of active provider connections. */
                        __('%s active connections', 'onesmtp'),
                        (string) $activeCount
                    )
                    : ($connectionCount > 0 ? __('Inactive', 'onesmtp') : __('Not connected', 'onesmtp')));

            $config = [
                'type' => $type,
                'label' => (string) $metadata['label'],
                'description' => (string) $metadata['description'],
                'connectionCount' => $connectionCount,
                'connections' => $this->connectionEditorData($connections),
                'endpoint' => rest_url('onesmtp/v1/providers'),
                'nonce' => wp_create_nonce('wp_rest'),
                'adminEmail' => sanitize_email((string) get_option('admin_email')),
                'quotaEnabled' => $this->featureGate->isEnabled(FeatureGate::PROVIDER_QUOTA_BUDGETS),
            ];

            echo '<article class="onesmtp-provider-list-item" data-provider-type="' . esc_attr((string) $type) . '">';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ProviderIcons renders an SVG selected from a private provider allowlist and escapes its attributes.
            echo '<span class="onesmtp-provider-list-icon" aria-hidden="true">' . ProviderIcons::render((string) $type) . '</span>';
            echo '<div class="onesmtp-provider-list-copy"><strong>' . esc_html((string) $metadata['label']) . '</strong><span>' . esc_html((string) $metadata['description']) . '</span>';
            $this->renderConnectionSummary($connections);
            echo '</div>';
            echo '<span class="onesmtp-provider-status ' . esc_attr($statusClass) . '">' . esc_html($statusLabel) . '</span>';
            echo '<div data-onesmtp-component="provider-inline-settings" data-onesmtp-provider-config="' . esc_attr((string) wp_json_encode($config)) . '"></div>';
            echo '</article>';
        }

        echo '</div></section>';
    }

    /**
     * Keep the configured connection visible as part of its provider row rather
     * than rendering a second, nested connection card below the row.
     *
     * @param array<int,array<string,mixed>> $connections
     */
    private function renderConnectionSummary(array $connections): void
    {
        if ($connections === []) {
            return;
        }

        if (count($connections) > 1) {
            $activeCount = count(array_filter($connections, static fn (array $provider): bool => ! empty($provider['is_active'])));
            echo '<span class="onesmtp-provider-connection-summary">' . esc_html(
                sprintf(
                    /* translators: 1: configured connection count, 2: active connection count. */
                    _n('%1$d configured connection · %2$d active', '%1$d configured connections · %2$d active', count($connections), 'onesmtp'),
                    count($connections),
                    $activeCount
                )
            ) . '</span>';

            return;
        }

        $connection = $connections[0];
        $providerId = (int) ($connection['id'] ?? 0);
        $providerName = trim((string) ($connection['name'] ?? ''));
        if ($providerName === '') {
            $providerName = sprintf(
                /* translators: %d: provider connection ID. */
                __('Provider #%d', 'onesmtp'),
                $providerId
            );
        }

        echo '<span class="onesmtp-provider-connection-summary">' . esc_html(
            sprintf(
                /* translators: 1: connection name, 2: priority, 3: weight. */
                __('%1$s · Priority %2$d · Weight %3$d', 'onesmtp'),
                $providerName,
                (int) ($connection['priority'] ?? 100),
                (int) ($connection['weight'] ?? 1)
            )
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- formatCircuitHealth returns only escaped translations and an escaped date inside fixed markup.
        ) . ' · ' . $this->formatCircuitHealth($connection) . '</span>';

        if ($providerId > 0 && $this->repository->hasCredentialRecoveryRequired($providerId)) {
            echo '<span class="onesmtp-provider-recovery">' . esc_html__('Credential update needed. Re-enter the affected credentials to restore delivery.', 'onesmtp') . '</span>';
        }
    }

    /**
     * Pass only redacted, non-sensitive provider data to the JavaScript editor.
     * Blank credential inputs in an update preserve the existing encrypted value.
     *
     * @param array<int,array<string,mixed>> $connections
     * @return array<int,array<string,mixed>>
     */
    private function connectionEditorData(array $connections): array
    {
        return array_values(array_map(function (array $connection): array {
            $providerId = (int) ($connection['id'] ?? 0);

            return [
                'id' => $providerId,
                'name' => (string) ($connection['name'] ?? ''),
                'priority' => (int) ($connection['priority'] ?? 100),
                'weight' => (int) ($connection['weight'] ?? 1),
                'isActive' => ! empty($connection['is_active']),
                'circuitState' => (string) ($connection['circuit_state'] ?? 'closed'),
                'credentialRecoveryRequired' => $providerId > 0 && $this->repository->hasCredentialRecoveryRequired($providerId),
                'config' => isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : [],
            ];
        }, $connections));
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

        $this->renderQuotaFields();

        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Leave credential fields blank when updating a provider to keep existing stored secrets.', 'onesmtp') . '</p>';
        submit_button(__('Save provider', 'onesmtp'));
        echo '</form>';
    }

    private function renderQuotaFields(): void
    {
        if (! $this->featureGate->isEnabled(FeatureGate::PROVIDER_QUOTA_BUDGETS)) {
            echo '<p class="description onesmtp-provider-quota-disabled">' . esc_html__('Per-provider sending budgets are available with Pro and remain disabled on this installation.', 'onesmtp') . '</p>';

            return;
        }

        echo '<tr><th scope="row"><span id="onesmtp-provider-quota-label">' . esc_html__('Provider sending budget', 'onesmtp') . '</span></th><td><p class="description" id="onesmtp-provider-quota-help">' . esc_html__('Count production send attempts for this provider across bounded windows. Enter 0 to disable a window; values above 1,000,000 are safely clamped.', 'onesmtp') . '</p></td></tr>';
        $quotaAttributes = [
            'min' => 0,
            'max' => ProviderQuotaSettings::MAX_LIMIT,
            'aria-describedby' => 'onesmtp-provider-quota-help',
        ];
        $this->renderTextInput('config[' . ProviderQuotaSettingsKey::PER_MINUTE . ']', __('Per-minute attempts', 'onesmtp'), '0', 'number', $quotaAttributes);
        $this->renderTextInput('config[' . ProviderQuotaSettingsKey::PER_HOUR . ']', __('Per-hour attempts', 'onesmtp'), '0', 'number', $quotaAttributes);
        $this->renderTextInput('config[' . ProviderQuotaSettingsKey::PER_DAY . ']', __('Per-day attempts', 'onesmtp'), '0', 'number', $quotaAttributes);
    }

    /** @param array<string,int|string> $extraAttributes */
    private function renderTextInput(string $name, string $label, string $value = '', string $type = 'text', array $extraAttributes = []): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($this->fieldId($name)) . '">' . esc_html($label) . '</label></th><td>';
        $attributes = '';
        foreach ($extraAttributes as $attribute => $attributeValue) {
            $attributes .= ' ' . esc_attr($attribute) . '="' . esc_attr((string) $attributeValue) . '"';
        }
        $input = '<input class="regular-text" id="' . esc_attr($this->fieldId($name)) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '"' . $attributes . '>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic attribute is escaped before this fixed input tag is emitted.
        echo $input;
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
            'region' => __('AWS Region', 'onesmtp'),
            'host' => __('SMTP host', 'onesmtp'),
            'port' => __('SMTP port', 'onesmtp'),
            'username' => __('Username', 'onesmtp'),
            'password' => __('Password', 'onesmtp'),
            'api_key' => __('API key', 'onesmtp'),
            'secret_key' => __('Secret key', 'onesmtp'),
            'send_token' => __('Send mail token', 'onesmtp'),
            'token' => __('API token', 'onesmtp'),
            'domain' => __('Sending domain', 'onesmtp'),
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
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('DNS TXT lookup is not available in this PHP environment. Aculect Mail can show expected records, but it cannot verify SPF, DKIM, or DMARC readiness here.', 'onesmtp') . '</p></div>';
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
            'amazon_ses' => __('Amazon SES', 'onesmtp'),
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
            $message .= ' ' . __('Add the provider DKIM selector when available so Aculect Mail can check the selector-specific TXT name.', 'onesmtp');
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

        if ($this->featureGate->isEnabled(FeatureGate::PROVIDER_QUOTA_BUDGETS)) {
            $quotaInput = [];
            foreach (ProviderQuotaSettingsKey::fields() as $field) {
                $quotaInput[substr($field, strlen('quota_'))] = $posted[$field] ?? 0;
            }

            $config = array_merge($config, ProviderQuotaSettings::fromArray($quotaInput)->toProviderConfig());
        }

        return $config;
    }

    /** @param array<string,mixed> $provider @return array{per_minute:int,per_hour:int,per_day:int} */
    private function quotaAuditLimits(array $provider): array
    {
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];

        return ProviderQuotaSettings::fromProviderConfig($config)->toArray();
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

    private function renderNotice(): void
    {
        $status = isset($_GET['onesmtp_provider_status']) ? sanitize_key(wp_unslash((string) $_GET['onesmtp_provider_status'])) : '';
        if ($status === '') {
            return;
        }

        $messages = [
            'failed' => __('Provider action failed.', 'onesmtp'),
            'suremail_imported' => __('The default SureMail connection was imported as inactive. Review and test it before making Aculect Mail the delivery owner.', 'onesmtp'),
            'suremail_import_failed' => __('SureMail import could not be completed. Analyze the setup again and review the reported requirements.', 'onesmtp'),
            'suremail_analysis_expired' => __('The SureMail analysis expired or the source configuration changed. Analyze it again before importing.', 'onesmtp'),
        ];
        $message = $messages[$status] ?? __('Provider action completed.', 'onesmtp');

        $isError = in_array($status, ['failed', 'suremail_import_failed', 'suremail_analysis_expired'], true);
        echo '<div class="notice ' . ($isError ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function renderSureMailCompatibility(): void
    {
        $analysis = get_transient($this->sureMailAnalysisKey());
        if (! is_array($analysis)) {
            $analysis = $this->sureMailMigration->analyze();
        }
        if (empty($analysis['detected'])) {
            return;
        }

        echo '<section class="onesmtp-compatibility-card" aria-labelledby="onesmtp-suremail-title">';
        echo '<div><h3 id="onesmtp-suremail-title">' . esc_html__('SureMail compatibility', 'onesmtp') . '</h3>';
        echo '<p>' . esc_html__('SureMail is present on this site. Only one mail plugin should own live delivery at a time. Aculect Mail will not disable SureMail or change its settings.', 'onesmtp') . '</p>';

        $hasAnalysis = isset($_GET['onesmtp_provider_status']) && sanitize_key(wp_unslash((string) $_GET['onesmtp_provider_status'])) === 'suremail_analyzed';
        if ($hasAnalysis) {
            if (! empty($analysis['supported'])) {
                $label = ProviderTypes::metadata()[(string) $analysis['target_type']]['label'] ?? (string) $analysis['target_type'];
                $analysisLabel = ! empty($analysis['importable']) ? __('Ready to import:', 'onesmtp') : __('Review required:', 'onesmtp');
                echo '<p class="onesmtp-compatibility-result"><strong>' . esc_html($analysisLabel) . '</strong> ' . esc_html((string) ($analysis['default_name'] ?: $label)) . ' (' . esc_html((string) $label) . '). ';
                $skipped = (int) $analysis['skipped_connections'];
                $skippedMessage = $skipped === 1
                    ? /* translators: %d: number of SureMail connections excluded from import. */ __('%d other connection will be skipped.', 'onesmtp')
                    : /* translators: %d: number of SureMail connections excluded from import. */ __('%d other connections will be skipped.', 'onesmtp');
                echo esc_html(sprintf($skippedMessage, $skipped)) . '</p>';
                if (! empty($analysis['already_configured'])) {
                    echo '<p>' . esc_html__('Aculect Mail already has this provider type configured, so import is disabled to preserve the one-provider/one-connection model.', 'onesmtp') . '</p>';
                }
                if (empty($analysis['version_supported'])) {
                    echo '<p>' . esc_html__('This SureMail version is outside the migration versions Aculect Mail can safely validate.', 'onesmtp') . '</p>';
                }
                if (! empty($analysis['missing_fields'])) {
                    echo '<p>' . esc_html(sprintf(
                        /* translators: %s: comma-separated provider credential field names. */
                        __('Import needs these required fields before it can continue: %s.', 'onesmtp'),
                        implode(', ', array_map('sanitize_key', (array) $analysis['missing_fields']))
                    )) . '</p>';
                }
            } else {
                echo '<p class="onesmtp-compatibility-result">' . esc_html__('No supported default SureMail connection was found. Nothing will be imported.', 'onesmtp') . '</p>';
            }
            echo '<p class="description">' . esc_html__('Email logs are never copied. Imported credentials are decrypted in memory and stored again using Aculect Mail AES-GCM encryption. The imported connection starts inactive.', 'onesmtp') . '</p>';
        }
        echo '</div><div class="onesmtp-compatibility-actions">';
        echo '<form method="post"><input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="suremail_analyze">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        submit_button(__('Analyze SureMail setup', 'onesmtp'), 'secondary', 'submit', false);
        echo '</form>';
        if ($hasAnalysis && ! empty($analysis['importable']) && empty($analysis['already_configured']) && ! empty($analysis['fingerprint'])) {
            echo '<form method="post"><input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="suremail_import"><input type="hidden" name="suremail_fingerprint" value="' . esc_attr((string) $analysis['fingerprint']) . '">';
            wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
            submit_button(__('Import default connection', 'onesmtp'), 'primary', 'submit', false);
            echo '</form>';
        }
        echo '</div></section>';
    }

    private function sureMailAnalysisKey(): string
    {
        return 'onesmtp_suremail_analysis_' . get_current_user_id();
    }

    private function redirect(string $status): void
    {
        $url = add_query_arg(
            ['onesmtp_provider_status' => $status],
            admin_url('options-general.php?page=onesmtp#onesmtp-providers')
        );

        wp_safe_redirect($url);
        if (defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING')) {
            throw new \RuntimeException('Aculect Mail provider admin redirected.');
        }

        exit;
    }

    private function fieldId(string $name): string
    {
        return 'onesmtp-provider-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower($name));
    }
}
