<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Core\TableNames;

final class SuppressionDerivationRepository
{
    public const CLAIMED = 'claimed';
    public const PROCESSED = 'processed';
    public const BUSY = 'busy';

    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    public function claim(string $externalEventHash, ?string $now = null): string
    {
        global $wpdb;

        $now = $now ?? current_time('mysql', true);
        $sql = $wpdb->prepare(
            'INSERT INTO ' . TableNames::suppressionDerivations() . ' (external_event_hash, status, created_at, updated_at) VALUES (%s, \'processing\', %s, %s) ON DUPLICATE KEY UPDATE id = id',
            $externalEventHash,
            $now,
            $now
        );
        $result = is_string($sql) ? $wpdb->query($sql) : false;
        if ($result === false) {
            return self::BUSY;
        }

        if ( (int) $result > 0 ) {
            return self::CLAIMED;
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, updated_at FROM ' . TableNames::suppressionDerivations() . ' WHERE external_event_hash = %s LIMIT 1',
                $externalEventHash
            ),
            ARRAY_A
        );
        if (is_array($existing) && (string) ($existing['status'] ?? '') === self::PROCESSED) {
            return self::PROCESSED;
        }

        $staleAt = gmdate('Y-m-d H:i:s', strtotime($now) - 300);
        $reclaim = $wpdb->prepare(
            'UPDATE ' . TableNames::suppressionDerivations() . ' SET status = \'processing\', updated_at = %s WHERE external_event_hash = %s AND (status = \'pending\' OR (status = \'processing\' AND updated_at < %s))',
            $now,
            $externalEventHash,
            $staleAt
        );
        if (is_string($reclaim) && (int) $wpdb->query($reclaim) > 0) {
            return self::CLAIMED;
        }

        return self::BUSY;
    }

    public function markProcessed(string $externalEventHash, ?string $now = null): bool
    {
        global $wpdb;

        $now = $now ?? current_time('mysql', true);
        $sql = $wpdb->prepare(
            'UPDATE ' . TableNames::suppressionDerivations() . ' SET status = \'processed\', processed_at = %s, updated_at = %s WHERE external_event_hash = %s AND status = \'processing\'',
            $now,
            $now,
            $externalEventHash
        );

        return is_string($sql) && (int) $wpdb->query($sql) > 0;
    }

    public function markPending(string $externalEventHash, ?string $now = null): bool
    {
        global $wpdb;

        $now = $now ?? current_time('mysql', true);
        $sql = $wpdb->prepare(
            'UPDATE ' . TableNames::suppressionDerivations() . ' SET status = \'pending\', updated_at = %s WHERE external_event_hash = %s AND status = \'processing\'',
            $now,
            $externalEventHash
        );

        return is_string($sql) && (int) $wpdb->query($sql) > 0;
    }

    public function prune(string $cutoff): void
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            'DELETE FROM ' . TableNames::suppressionDerivations() . ' WHERE updated_at < %s LIMIT 500',
            $cutoff
        );
        if (is_string($sql)) {
            $wpdb->query($sql);
        }
    }
}
