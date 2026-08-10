<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Core\TableNames;

final class SuppressionRepository
{
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    public function upsert(
        string $fingerprint,
        string $domain,
        string $reason,
        string $provider,
        ?int $providerId,
        ?string $providerMessageId,
        string $firstSeen,
        string $expiryAt
    ): bool {
        global $wpdb;

        $now = current_time('mysql', true);
        $providerValue = $providerId !== null && $providerId > 0 ? '%d' : 'NULL';
        $messageValue = $providerMessageId === null || trim($providerMessageId) === '' ? 'NULL' : '%s';
        $args = [$fingerprint, $domain, $reason, $provider];
        if ($providerValue === '%d') {
            $args[] = $providerId;
        }
        if ($messageValue === '%s') {
            $args[] = $providerMessageId;
        }
        array_push($args, $firstSeen, $now, $expiryAt, $now);

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Conditional nullable placeholders are assembled with the matching arguments above.
        $sql = $wpdb->prepare(
            'INSERT INTO ' . TableNames::suppressions() . ' (recipient_fingerprint, recipient_domain, reason_code, provider, provider_id, provider_message_id, first_seen, last_seen, expiry_at, occurrence_count, created_at, updated_at) VALUES (%s, %s, %s, %s, ' . $providerValue . ', ' . $messageValue . ', %s, %s, %s, 1, %s, %s) ON DUPLICATE KEY UPDATE reason_code = VALUES(reason_code), provider = VALUES(provider), provider_id = VALUES(provider_id), provider_message_id = VALUES(provider_message_id), last_seen = VALUES(last_seen), expiry_at = VALUES(expiry_at), occurrence_count = occurrence_count + 1, updated_at = VALUES(updated_at)',
            ...$args
        );

        return is_string($sql) && $wpdb->query($sql) !== false;
    }

    public function hasActive(string $fingerprint, ?string $now = null): bool
    {
        global $wpdb;
        $now = $now ?? current_time('mysql', true);
        $sql = $wpdb->prepare(
            'SELECT id FROM ' . TableNames::suppressions() . ' WHERE recipient_fingerprint = %s AND expiry_at > %s LIMIT 1',
            $fingerprint,
            $now
        );

        return is_numeric($wpdb->get_var($sql));
    }

    /** @return array<int,array<string,mixed>> */
    public function list(int $limit = 100): array
    {
        global $wpdb;
        $limit = max(1, min(200, $limit));
        $sql = $wpdb->prepare(
            'SELECT recipient_fingerprint, recipient_domain, reason_code, provider, provider_id, provider_message_id, first_seen, last_seen, expiry_at, occurrence_count FROM ' . TableNames::suppressions() . ' ORDER BY updated_at DESC LIMIT %d',
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,mixed>|null */
    public function find(string $fingerprint): ?array
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            'SELECT recipient_fingerprint, recipient_domain, reason_code, provider, provider_id, provider_message_id, first_seen, last_seen, expiry_at, occurrence_count FROM ' . TableNames::suppressions() . ' WHERE recipient_fingerprint = %s LIMIT 1',
            $fingerprint
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function remove(string $fingerprint): bool
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            'DELETE FROM ' . TableNames::suppressions() . ' WHERE recipient_fingerprint = %s',
            $fingerprint
        );

        return is_string($sql) && $wpdb->query($sql) !== false;
    }

    public function prune(string $cutoff): void
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            'DELETE FROM ' . TableNames::suppressions() . ' WHERE expiry_at <= %s OR last_seen < %s LIMIT 500',
            current_time('mysql', true),
            $cutoff
        );
        if (is_string($sql)) {
            $wpdb->query($sql);
        }
    }
}
