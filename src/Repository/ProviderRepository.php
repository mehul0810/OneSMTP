<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Core\TableNames;
use OneSMTP\Providers\ProviderStateCache;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Security\SecretVault;

final class ProviderRepository
{
    private SecretVault $vault;
    private ProviderStateCache $cache;

    public function __construct(?SecretVault $vault = null, ?ProviderStateCache $cache = null)
    {
        $this->vault = $vault ?? new SecretVault();
        $this->cache = $cache ?? new ProviderStateCache();
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

    public function getAll(): array
    {
        global $wpdb;

        $sql = 'SELECT * FROM ' . TableNames::providers() . ' ORDER BY priority ASC, id ASC';
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? array_map([$this, 'mapProviderRow'], $rows) : [];
    }

    public function find(int $providerId): ?array
    {
        global $wpdb;

        $sql = $wpdb->prepare('SELECT * FROM ' . TableNames::providers() . ' WHERE id = %d', $providerId);
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $this->mapProviderRow($row) : null;
    }

    public function save(array $provider): int
    {
        global $wpdb;

        $type = isset($provider['adapter_type']) ? sanitize_key((string) $provider['adapter_type']) : '';
        if (! ProviderTypes::isSupported($type)) {
            return 0;
        }

        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $config = $this->encryptSecrets($config);

        $payload = [
            'slug'          => sanitize_key((string) ($provider['slug'] ?? $type . '_' . wp_generate_password(8, false))),
            'name'          => sanitize_text_field((string) ($provider['name'] ?? strtoupper($type))),
            'adapter_type'  => $type,
            'priority'      => max(1, (int) ($provider['priority'] ?? 100)),
            'weight'        => max(1, (int) ($provider['weight'] ?? 1)),
            'is_active'     => ! empty($provider['is_active']) ? 1 : 0,
            'circuit_state' => sanitize_key((string) ($provider['circuit_state'] ?? 'closed')),
            'circuit_until' => isset($provider['circuit_until']) ? (string) $provider['circuit_until'] : null,
            'config_json'   => wp_json_encode($config),
            'updated_at'    => current_time('mysql', true),
        ];

        $id = isset($provider['id']) ? (int) $provider['id'] : 0;
        if ($id > 0) {
            $wpdb->update(
                TableNames::providers(),
                $payload,
                ['id' => $id],
                ['%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            do_action('onesmtp_provider_saved', $id);

            return $id;
        }

        $payload['created_at'] = current_time('mysql', true);

        $inserted = $wpdb->insert(
            TableNames::providers(),
            $payload,
            ['%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return 0;
        }

        $providerId = (int) $wpdb->insert_id;
        do_action('onesmtp_provider_saved', $providerId);

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

        $wpdb->update(
            TableNames::providers(),
            [
                'circuit_state' => sanitize_key($state),
                'circuit_until' => $until,
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
        $row['config'] = $this->decodeConfig(isset($row['config_json']) ? (string) $row['config_json'] : '');

        return $row;
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

            if (! preg_match('/pass|secret|token|api(?:_|-)?key/i', (string) $key)) {
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
                $config[$key] = '';
            }
        }

        return $config;
    }
}
