<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

use InvalidArgumentException;
use OneSMTP\Alerts\FailureAlertSettings;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\Redactor;

final class SettingsTransferService
{
    private const SCHEMA_VERSION = 1;
    private const PLUGIN_SLUG = 'onesmtp';
    private const MAX_IMPORT_BYTES = 262144;
    private const SENSITIVE_KEY_PATTERN = '/pass|password|secret|token|api(?:_|-)?key|credential/i';
    private const SAFE_PROVIDER_CONFIG_KEYS = [
        'host' => true,
        'port' => true,
    ];

    public function __construct(
        private ?SettingsRepository $settings = null,
        private ?ProviderRepository $providers = null,
        private ?Redactor $redactor = null
    ) {
        $this->settings = $settings ?? new SettingsRepository();
        $this->providers = $providers ?? new ProviderRepository();
        $this->redactor = $redactor ?? new Redactor();
    }

    /**
     * @return array<string,mixed>
     */
    public function export(): array
    {
        $settings = $this->settings->getAll();
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'plugin' => self::PLUGIN_SLUG,
            'generated_at' => current_time('mysql', true),
            'privacy' => [
                'secrets' => 'excluded',
                'excluded_fields' => [
                    'provider passwords, API keys, tokens, client secrets, refresh tokens, and credential-like values',
                    'alert recipients, webhook URLs, Reply-To, BCC, raw recipients, message bodies, raw headers, and payload JSON',
                ],
            ],
            'settings' => [
                'sender_identity' => $this->exportSenderIdentity($settings['sender_identity'] ?? []),
                'rate_limits' => $this->exportRateLimits($settings['rate_limits'] ?? []),
                'background_sending' => $this->exportBackgroundSending($settings['background_sending'] ?? []),
                'attachment_logging' => $this->exportAttachmentLogging($settings['attachment_logging'] ?? []),
                'failure_alerts' => $this->exportFailureAlerts($settings['failure_alerts'] ?? []),
            ],
            'providers' => $this->exportProviders(),
        ];

        do_action('onesmtp_settings_exported', [
            'schema_version' => self::SCHEMA_VERSION,
            'settings_groups' => array_keys($payload['settings']),
            'provider_count' => count($payload['providers']),
            'secrets' => 'excluded',
        ]);

        return $payload;
    }

    /**
     * @return array{settings_groups:array<int,string>,providers_imported:int,excluded_fields:int}
     */
    public function importJson(string $json, string $nonceField = 'onesmtp_settings_import_nonce'): array
    {
        $json = trim($json);
        if ($json === '') {
            throw new InvalidArgumentException('Import JSON is empty.');
        }

        if (strlen($json) > self::MAX_IMPORT_BYTES) {
            throw new InvalidArgumentException('Import JSON is too large.');
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Import JSON must be a valid JSON object.');
        }

        return $this->import($decoded, $nonceField);
    }

    /**
     * @param array<string,mixed> $payload Import payload.
     * @return array{settings_groups:array<int,string>,providers_imported:int,excluded_fields:int}
     */
    public function import(array $payload, string $nonceField = 'onesmtp_settings_import_nonce'): array
    {
        $summary = [
            'settings_groups' => [],
            'providers_imported' => 0,
            'excluded_fields' => 0,
        ];

        $nextSettings = $this->settings->getAll();
        $settings = $payload['settings'] ?? [];
        if (isset($payload['settings']) && ! is_array($settings)) {
            throw new InvalidArgumentException('Import settings must be a JSON object.');
        }

        if (is_array($settings) && array_key_exists('sender_identity', $settings)) {
            if (! is_array($settings['sender_identity'])) {
                throw new InvalidArgumentException('Sender identity settings must be a JSON object.');
            }

            [$nextSettings['sender_identity'], $excluded] = $this->importSenderIdentity($settings['sender_identity']);
            $summary['excluded_fields'] += $excluded;
            $summary['settings_groups'][] = 'sender_identity';
        }

        if (is_array($settings) && array_key_exists('rate_limits', $settings)) {
            if (! is_array($settings['rate_limits'])) {
                throw new InvalidArgumentException('Rate limit settings must be a JSON object.');
            }

            $nextSettings['rate_limits'] = RateLimitSettings::fromArray($settings['rate_limits'])->toArray();
            $summary['settings_groups'][] = 'rate_limits';
        }

        if (is_array($settings) && array_key_exists('failure_alerts', $settings)) {
            if (! is_array($settings['failure_alerts'])) {
                throw new InvalidArgumentException('Failure alert settings must be a JSON object.');
            }

            [$nextSettings['failure_alerts'], $excluded] = $this->importFailureAlerts($settings['failure_alerts']);
            $summary['excluded_fields'] += $excluded;
            $summary['settings_groups'][] = 'failure_alerts';
        }

        if (is_array($settings) && array_key_exists('background_sending', $settings)) {
            if (! is_array($settings['background_sending'])) {
                throw new InvalidArgumentException('Background sending settings must be a JSON object.');
            }

            $nextSettings['background_sending'] = BackgroundSendingSettings::fromArray($settings['background_sending'])->toArray();
            $summary['settings_groups'][] = 'background_sending';
        }

        if (is_array($settings) && array_key_exists('attachment_logging', $settings)) {
            if (! is_array($settings['attachment_logging'])) {
                throw new InvalidArgumentException('Attachment logging settings must be a JSON object.');
            }

            $nextSettings['attachment_logging'] = AttachmentLoggingSettings::fromArray($settings['attachment_logging'])->toArray();
            $summary['settings_groups'][] = 'attachment_logging';
        }

        $providers = $payload['providers'] ?? [];
        if (isset($payload['providers']) && ! is_array($providers)) {
            throw new InvalidArgumentException('Providers must be a JSON array.');
        }

        $providerImports = [];
        $providerExcluded = 0;
        if (is_array($providers) && $providers !== []) {
            [$providerImports, $providerExcluded] = $this->prepareProviderImports($providers);
        }

        if ($summary['settings_groups'] === [] && $providerImports === []) {
            throw new InvalidArgumentException('Import JSON does not contain supported OneSMTP settings.');
        }

        if ($summary['settings_groups'] !== []) {
            $this->settings->save($nextSettings, $nonceField, 'import_settings');
        }

        foreach ($providerImports as $providerImport) {
            if ($this->providers->save($providerImport) > 0) {
                $summary['providers_imported']++;
            }
        }

        $summary['excluded_fields'] += $providerExcluded;
        do_action('onesmtp_settings_imported', $summary);

        return $summary;
    }

    /**
     * @param mixed $settings Sender settings.
     * @return array<string,mixed>
     */
    private function exportSenderIdentity(mixed $settings): array
    {
        $identity = SenderIdentity::fromArray(is_array($settings) ? $settings : [])->toArray();

        return [
            'from_email' => $identity['from_email'],
            'from_name' => $identity['from_name'],
            'force_from_email' => $identity['force_from_email'],
            'force_from_name' => $identity['force_from_name'],
            'force_reply_to' => $identity['force_reply_to'],
            'force_bcc' => $identity['force_bcc'],
        ];
    }

    /**
     * @param mixed $settings Rate settings.
     * @return array<string,int>
     */
    private function exportRateLimits(mixed $settings): array
    {
        return RateLimitSettings::fromArray(is_array($settings) ? $settings : [])->toArray();
    }

    /**
     * @param mixed $settings Background sending settings.
     * @return array<string,bool>
     */
    private function exportBackgroundSending(mixed $settings): array
    {
        return BackgroundSendingSettings::fromArray(is_array($settings) ? $settings : [])->toArray();
    }

    /**
     * @param mixed $settings Attachment logging settings.
     * @return array<string,bool>
     */
    private function exportAttachmentLogging(mixed $settings): array
    {
        return AttachmentLoggingSettings::fromArray(is_array($settings) ? $settings : [])->toArray();
    }

    /**
     * @param mixed $settings Alert settings.
     * @return array<string,int>
     */
    private function exportFailureAlerts(mixed $settings): array
    {
        $alerts = FailureAlertSettings::fromArray(is_array($settings) ? $settings : [])->toArray();

        return [
            'throttle_seconds' => $alerts['throttle_seconds'],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function exportProviders(): array
    {
        $exported = [];

        foreach ($this->providers->getAllSafe() as $provider) {
            if (! is_array($provider)) {
                continue;
            }

            $exported[] = [
                'slug' => sanitize_key((string) ($provider['slug'] ?? '')),
                'name' => $this->safeText((string) ($provider['name'] ?? '')),
                'adapter_type' => sanitize_key((string) ($provider['adapter_type'] ?? '')),
                'priority' => max(1, (int) ($provider['priority'] ?? 100)),
                'weight' => max(1, (int) ($provider['weight'] ?? 1)),
                'is_active' => ! empty($provider['is_active']),
                'config' => $this->exportProviderConfig(isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : []),
            ];
        }

        return $exported;
    }

    /**
     * @param array<string,mixed> $config Provider config.
     * @return array<string,string>
     */
    private function exportProviderConfig(array $config): array
    {
        $safe = [];

        foreach ($config as $key => $value) {
            $key = sanitize_key((string) $key);
            if (! isset(self::SAFE_PROVIDER_CONFIG_KEYS[$key]) || $this->isSensitiveKey($key) || ! is_scalar($value)) {
                continue;
            }

            $safe[$key] = $this->safeText((string) $value);
        }

        return $safe;
    }

    /**
     * @param array<string,mixed> $settings Sender settings.
     * @return array{0:array<string,mixed>,1:int}
     */
    private function importSenderIdentity(array $settings): array
    {
        $allowed = [
            'from_email' => true,
            'from_name' => true,
            'force_from_email' => true,
            'force_from_name' => true,
            'force_reply_to' => true,
            'force_bcc' => true,
        ];

        $safe = [];
        $excluded = 0;
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (! isset($allowed[$key])) {
                $excluded++;
                continue;
            }

            $safe[$key] = $value;
        }

        return [SenderIdentity::fromArray($safe)->toArray(), $excluded];
    }

    /**
     * @param array<string,mixed> $settings Alert settings.
     * @return array{0:array<string,mixed>,1:int}
     */
    private function importFailureAlerts(array $settings): array
    {
        $safe = [];
        $excluded = 0;

        foreach ($settings as $key => $value) {
            if ((string) $key !== 'throttle_seconds') {
                $excluded++;
                continue;
            }

            $safe['throttle_seconds'] = $value;
        }

        return [FailureAlertSettings::fromArray($safe)->toArray(), $excluded];
    }

    /**
     * @param array<int|string,mixed> $providers Providers.
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    private function prepareProviderImports(array $providers): array
    {
        $existingBySlug = [];
        foreach ($this->providers->getAll() as $existing) {
            if (! is_array($existing)) {
                continue;
            }

            $slug = sanitize_key((string) ($existing['slug'] ?? ''));
            if ($slug !== '') {
                $existingBySlug[$slug] = (int) ($existing['id'] ?? 0);
            }
        }

        $normalizedProviders = [];
        $excluded = 0;
        foreach ($providers as $provider) {
            if (! is_array($provider)) {
                throw new InvalidArgumentException('Each provider import entry must be a JSON object.');
            }

            [$normalized, $providerExcluded] = $this->normalizeProviderImport($provider, $existingBySlug);
            $excluded += $providerExcluded;
            $normalizedProviders[] = $normalized;
        }

        return [$normalizedProviders, $excluded];
    }

    /**
     * @param array<string,mixed> $provider Provider.
     * @param array<string,int>   $existingBySlug Existing provider ids keyed by slug.
     * @return array{0:array<string,mixed>,1:int}
     */
    private function normalizeProviderImport(array $provider, array $existingBySlug): array
    {
        $type = isset($provider['adapter_type']) ? sanitize_key((string) $provider['adapter_type']) : '';
        if (! ProviderTypes::isSupported($type)) {
            throw new InvalidArgumentException('Provider type is not supported.');
        }

        $slug = isset($provider['slug']) ? sanitize_key((string) $provider['slug']) : '';
        if ($slug === '') {
            $slug = $type . '_' . wp_generate_password(8, false);
        }

        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        [$safeConfig, $excluded] = $this->importProviderConfig($config);

        $normalized = [
            'slug' => $slug,
            'name' => isset($provider['name']) ? $this->safeText((string) $provider['name']) : strtoupper($type),
            'adapter_type' => $type,
            'priority' => isset($provider['priority']) ? max(1, absint($provider['priority'])) : 100,
            'weight' => isset($provider['weight']) ? max(1, absint($provider['weight'])) : 1,
            'is_active' => ! empty($provider['is_active']) ? 1 : 0,
            'config' => $safeConfig,
        ];

        if (isset($existingBySlug[$slug]) && $existingBySlug[$slug] > 0) {
            $normalized['id'] = $existingBySlug[$slug];
        }

        foreach ($provider as $key => $value) {
            if (! in_array((string) $key, ['slug', 'name', 'adapter_type', 'priority', 'weight', 'is_active', 'config'], true)) {
                $excluded++;
            }
        }

        return [$normalized, $excluded];
    }

    /**
     * @param array<string,mixed> $config Provider config.
     * @return array{0:array<string,string>,1:int}
     */
    private function importProviderConfig(array $config): array
    {
        $safe = [];
        $excluded = 0;

        foreach ($config as $key => $value) {
            $key = sanitize_key((string) $key);
            if (! isset(self::SAFE_PROVIDER_CONFIG_KEYS[$key]) || $this->isSensitiveKey($key) || ! is_scalar($value)) {
                $excluded++;
                continue;
            }

            $safe[$key] = $this->safeText((string) $value);
        }

        return [$safe, $excluded];
    }

    private function safeText(string $value): string
    {
        return $this->redactor->redactText(sanitize_text_field($value), 2048);
    }

    private function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match(self::SENSITIVE_KEY_PATTERN, $key);
    }
}
