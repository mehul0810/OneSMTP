<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Core\TableNames;
use OneSMTP\Providers\ProviderStateCache;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Security\Redactor;
use OneSMTP\Security\SecretVault;
use OneSMTP\Quota\ProviderQuotaSettings;
use OneSMTP\Quota\ProviderQuotaSettingsKey;

final class ProviderRepository
{
    /*
     * Repository queries use only plugin-owned identifiers from TableNames.
     * Every runtime value is passed through wpdb::prepare() or a typed wpdb
     * CRUD method before execution. Plugin Check cannot follow that invariant
     * across the TableNames helper and intermediate prepared SQL variables.
     */
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    private SecretVault $vault;
    private ProviderStateCache $cache;
    private Redactor $redactor;

    public function __construct(?SecretVault $vault = null, ?ProviderStateCache $cache = null, ?Redactor $redactor = null)
    {
        $this->vault = $vault ?? new SecretVault();
        $this->cache = $cache ?? new ProviderStateCache();
        $this->redactor = $redactor ?? new Redactor();
    }

    public function getActiveProviders(): array
    {
        $cached = $this->cache->get();
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        $sql = 'SELECT * FROM ' . TableNames::providers() . " WHERE is_active = 1 ORDER BY priority ASC, id ASC";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $rows = is_array($rows) ? array_map([$this, 'mapProviderRow'], $rows) : [];

        $this->cache->remember($rows);

        return $rows;
    }

    public function findActiveByType(string $adapterType): ?array
    {
        $adapterType = sanitize_key($adapterType);

        foreach ($this->getActiveProviders() as $provider) {
            if (sanitize_key((string) ($provider['adapter_type'] ?? '')) === $adapterType) {
                return $provider;
            }
        }

        return null;
    }

    public function getAll(): array
    {
        global $wpdb;

        $sql = 'SELECT * FROM ' . TableNames::providers() . ' ORDER BY priority ASC, id ASC';
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? array_map([$this, 'mapProviderRow'], $rows) : [];
    }

    public function getAllSafe(): array
    {
        return array_map([$this, 'safeProvider'], $this->getAll());
    }

    public function find(int $providerId): ?array
    {
        global $wpdb;

        $sql = $wpdb->prepare('SELECT * FROM ' . TableNames::providers() . ' WHERE id = %d', $providerId);
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $this->mapProviderRow($row) : null;
    }

    public function findSafe(int $providerId): ?array
    {
        $provider = $this->find($providerId);

        return is_array($provider) ? $this->safeProvider($provider) : null;
    }

    public function hasCredentialRecoveryRequired(int $providerId): bool
    {
        if ($providerId <= 0) {
            return false;
        }

        $config = $this->getRawConfig($providerId);
        foreach ($config as $key => $value) {
            if (! is_string($value) || ! $this->isSensitiveKey((string) $key) || ! $this->vault->isEncrypted($value)) {
                continue;
            }

            try {
                $this->vault->decrypt($value);
            } catch (\RuntimeException $e) {
                return true;
            }
        }

        return false;
    }

    public function save(array $provider): int
    {
        global $wpdb;

        $type = isset($provider['adapter_type']) ? sanitize_key((string) $provider['adapter_type']) : '';
        if (! ProviderTypes::isSupported($type)) {
            return 0;
        }

        $lockKey = 'onesmtp_provider_type_lock_' . md5($type);
        if (! $this->acquireTypeLock($lockKey)) {
            return 0;
        }

        $id = isset($provider['id']) ? (int) $provider['id'] : 0;
        foreach ($this->getAll() as $existingProvider) {
            if ((string) ($existingProvider['adapter_type'] ?? '') === $type && (int) ($existingProvider['id'] ?? 0) !== $id) {
                delete_option($lockKey);
                return 0;
            }
        }
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        if ($id > 0) {
            $config = $this->preserveStoredSensitiveConfig($id, $config);
            $config = $this->preserveStoredQuotaConfig($id, $config);
        }

        $config = $this->normalizeQuotaConfig($config);
        $config = $this->encryptSecrets($config);

        $payload = [
            'slug'          => sanitize_key((string) ($provider['slug'] ?? $type . '_' . wp_generate_password(8, false))),
            'name'          => sanitize_text_field((string) ($provider['name'] ?? strtoupper($type))),
            'adapter_type'  => $type,
            'priority'      => max(1, (int) ($provider['priority'] ?? 100)),
            'weight'        => max(1, (int) ($provider['weight'] ?? 1)),
            'is_active'     => ! empty($provider['is_active']) ? 1 : 0,
            'circuit_state' => $this->normalizeCircuitState((string) ($provider['circuit_state'] ?? 'closed')),
            'circuit_until' => $this->normalizeCircuitUntil(
                isset($provider['circuit_until']) ? (string) $provider['circuit_until'] : null,
                (string) ($provider['circuit_state'] ?? 'closed')
            ),
            'config_json'   => wp_json_encode($config),
            'updated_at'    => current_time('mysql', true),
        ];

        if ($id > 0) {
            $wpdb->update(
                TableNames::providers(),
                $payload,
                ['id' => $id],
                ['%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            do_action('onesmtp_provider_saved', $id);
            delete_option($lockKey);

            return $id;
        }

        $payload['created_at'] = current_time('mysql', true);

        $inserted = $wpdb->insert(
            TableNames::providers(),
            $payload,
            ['%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            delete_option($lockKey);
            return 0;
        }

        $providerId = (int) $wpdb->insert_id;
        do_action('onesmtp_provider_saved', $providerId);
        delete_option($lockKey);

        return $providerId;
    }

    public function delete(int $providerId): bool
    {
        global $wpdb;

        $deleted = $wpdb->delete(TableNames::providers(), ['id' => $providerId], ['%d']);
        if (! is_numeric($deleted)) {
            return false;
        }

        do_action('onesmtp_provider_deleted', $providerId);

        return ((int) $deleted) > 0;
    }

    public function markState(int $providerId, string $state, ?string $until = null): void
    {
        global $wpdb;

        $state = $this->normalizeCircuitState($state);

        $wpdb->update(
            TableNames::providers(),
            [
                'circuit_state' => $state,
                'circuit_until' => $this->normalizeCircuitUntil($until, $state),
                'updated_at'    => current_time('mysql', true),
            ],
            ['id' => $providerId],
            ['%s', '%s', '%s'],
            ['%d']
        );

        do_action('onesmtp_provider_state_changed', $providerId, $state);
    }

    private function mapProviderRow(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['priority'] = (int) ($row['priority'] ?? 100);
        $row['weight'] = (int) ($row['weight'] ?? 1);
        $row['is_active'] = (int) ($row['is_active'] ?? 0);
        $row['circuit_state'] = $this->normalizeCircuitState((string) ($row['circuit_state'] ?? 'closed'));
        $row['circuit_until'] = $this->normalizeCircuitUntil(
            isset($row['circuit_until']) ? (string) $row['circuit_until'] : null,
            (string) $row['circuit_state']
        );
        $row['config'] = $this->decodeConfig(isset($row['config_json']) ? (string) $row['config_json'] : '');

        return $row;
    }

    private function normalizeCircuitState(string $state): string
    {
        return sanitize_key($state) === 'open' ? 'open' : 'closed';
    }

    private function normalizeCircuitUntil(?string $until, string $state): ?string
    {
        if ($this->normalizeCircuitState($state) !== 'open' || $until === null) {
            return null;
        }

        $until = sanitize_text_field($until);
        if ($until === '') {
            return null;
        }

        $timestamp = strtotime($until);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function safeProvider(array $provider): array
    {
        unset($provider['config_json']);

        $provider['config'] = $this->redactor->redactArray(
            isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : []
        );

        return $provider;
    }

    private function decodeConfig(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        return $this->decryptSecrets($decoded);
    }

    private function encryptSecrets(array $config): array
    {
        foreach ($config as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (! $this->isSensitiveKey((string) $key)) {
                continue;
            }

            $config[$key] = $this->vault->encrypt($value);
        }

        return $config;
    }

    private function decryptSecrets(array $config): array
    {
        foreach ($config as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (! $this->vault->isEncrypted($value)) {
                continue;
            }

            try {
                $config[$key] = $this->vault->decrypt($value);
            } catch (\RuntimeException $e) {
                unset($config[$key]);
            }
        }

        return $config;
    }

    private function preserveStoredSensitiveConfig(int $providerId, array $config): array
    {
        $storedConfig = $this->getRawConfig($providerId);
        foreach ($storedConfig as $key => $value) {
            if (array_key_exists($key, $config) || ! is_string($value) || ! $this->isSensitiveKey((string) $key)) {
                continue;
            }

            $config[$key] = $value;
        }

        return $config;
    }

    /**
     * Keep an existing non-secret budget when a free/core update omits the
     * Pro-only field. This preserves provider configuration without enabling
     * quota enforcement on installations where the feature gate is absent.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function preserveStoredQuotaConfig(int $providerId, array $config): array
    {
        if (array_intersect(ProviderQuotaSettingsKey::fields(), array_keys($config)) !== []) {
            return $config;
        }

        $storedConfig = $this->getRawConfig($providerId);
        $storedQuota = ProviderQuotaSettings::fromProviderConfig($storedConfig);
        if (! $storedQuota->hasAnyLimit() && array_intersect(ProviderQuotaSettingsKey::fields(), array_keys($storedConfig)) === []) {
            return $config;
        }

        $config = array_merge($config, $storedQuota->toProviderConfig());

        return $config;
    }

    /**
     * Normalize non-secret budget values at the storage boundary as a second
     * line of defence for REST or extension callers that bypass the admin UI.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function normalizeQuotaConfig(array $config): array
    {
        if (array_intersect(ProviderQuotaSettingsKey::fields(), array_keys($config)) === []) {
            return $config;
        }

        return array_merge($config, ProviderQuotaSettings::fromProviderConfig($config)->toProviderConfig());
    }

    /**
     * @return array<string,mixed>
     */
    private function getRawConfig(int $providerId): array
    {
        global $wpdb;

        $sql = $wpdb->prepare('SELECT * FROM ' . TableNames::providers() . ' WHERE id = %d', $providerId);
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (! is_array($row)) {
            return [];
        }

        $decoded = json_decode(isset($row['config_json']) ? (string) $row['config_json'] : '', true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/pass|secret|token|api(?:_|-)?key|signing/i', $key);
    }

    private function acquireTypeLock(string $lockKey): bool
    {
        $expiresAt = time() + 15;
        if (add_option($lockKey, $expiresAt, '', false)) {
            return true;
        }

        $existingExpiry = (int) get_option($lockKey, 0);
        if ($existingExpiry > time()) {
            return false;
        }

        delete_option($lockKey);

        return add_option($lockKey, $expiresAt, '', false);
    }
}
