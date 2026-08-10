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

    /**
     * Claim an event hash and return the ownership token, or a terminal status.
     *
     * The token is deliberately longer than either status sentinel, so callers
     * can distinguish an owned claim from a processed or busy row without a
     * second read.
     */
    public function claim(string $externalEventHash, ?string $now = null): string
    {
        global $wpdb;

        $now = $now ?? current_time('mysql', true);
        $claimToken = $this->newClaimToken();
        if ($claimToken === null) {
            return self::BUSY;
        }

        $sql = $wpdb->prepare(
            'INSERT INTO ' . TableNames::suppressionDerivations() . ' (external_event_hash, claim_token, status, created_at, updated_at) VALUES (%s, %s, \'processing\', %s, %s) ON DUPLICATE KEY UPDATE id = id',
            $externalEventHash,
            $claimToken,
            $now,
            $now
        );
        $result = is_string($sql) ? $wpdb->query($sql) : false;
        if ($result === false) {
            return self::BUSY;
        }

        if ( (int) $result > 0 ) {
            return $claimToken;
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, claim_token, updated_at FROM ' . TableNames::suppressionDerivations() . ' WHERE external_event_hash = %s LIMIT 1',
                $externalEventHash
            ),
            ARRAY_A
        );
        if (is_array($existing) && (string) ($existing['status'] ?? '') === self::PROCESSED) {
            return self::PROCESSED;
        }

        $staleAt = gmdate('Y-m-d H:i:s', strtotime($now) - 300);
        $reclaim = $wpdb->prepare(
            'UPDATE ' . TableNames::suppressionDerivations() . ' SET claim_token = %s, status = \'processing\', updated_at = %s WHERE external_event_hash = %s AND (status = \'pending\' OR (status = \'processing\' AND updated_at < %s))',
            $claimToken,
            $now,
            $externalEventHash,
            $staleAt
        );
        if (is_string($reclaim) && (int) $wpdb->query($reclaim) > 0) {
            return $claimToken;
        }

        return self::BUSY;
    }

    public function markProcessed(string $externalEventHash, string $claimToken, ?string $now = null): bool
    {
        global $wpdb;

        if ( ! $this->isValidClaimToken($claimToken) ) {
            return false;
        }

        $now = $now ?? current_time('mysql', true);
        $sql = $wpdb->prepare(
            'UPDATE ' . TableNames::suppressionDerivations() . ' SET status = \'processed\', claim_token = NULL, processed_at = %s, updated_at = %s WHERE external_event_hash = %s AND status = \'processing\' AND claim_token = %s',
            $now,
            $now,
            $externalEventHash,
            $claimToken
        );

        return is_string($sql) && (int) $wpdb->query($sql) > 0;
    }

    public function markPending(string $externalEventHash, string $claimToken, ?string $now = null): bool
    {
        global $wpdb;

        if ( ! $this->isValidClaimToken($claimToken) ) {
            return false;
        }

        $now = $now ?? current_time('mysql', true);
        $sql = $wpdb->prepare(
            'UPDATE ' . TableNames::suppressionDerivations() . ' SET status = \'pending\', claim_token = NULL, updated_at = %s WHERE external_event_hash = %s AND status = \'processing\' AND claim_token = %s',
            $now,
            $externalEventHash,
            $claimToken
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

    private function newClaimToken(): ?string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable) {
            return null;
        }
    }

    private function isValidClaimToken(string $claimToken): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $claimToken) === 1;
    }
}
