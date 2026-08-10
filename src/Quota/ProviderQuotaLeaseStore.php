<?php

declare(strict_types=1);

namespace OneSMTP\Quota;

use OneSMTP\Core\TableNames;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- TableNames supplies plugin-owned identifiers and all runtime values are prepared.

/**
 * Database-backed fencing for quota checks and in-flight sends.
 *
 * The unique lease key and conditional upsert make acquisition atomic on the
 * WordPress database. Owner tokens make expiry and release safe when an old
 * worker wakes after a newer worker has taken over. Expired rows are pruned in
 * bounded batches; active leases are never deleted by cleanup.
 */
final class ProviderQuotaLeaseStore
{
    private const CLEANUP_BATCH = 100;

    /** @var callable():string */
    private $tokenGenerator;

    /** @var callable():int */
    private $clock;

    public function __construct(?callable $clock = null, ?callable $tokenGenerator = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
        $this->tokenGenerator = $tokenGenerator ?? static fn (): string => bin2hex(random_bytes(32));
    }

    public function acquireLock(string $leaseKey, int $ttl): ?string
    {
        $token = $this->newToken();
        $now = $this->now();
        $expiresAt = $now + max(1, $ttl);
        $this->pruneExpired($now);

        global $wpdb;

        $table = TableNames::quotaLeases();
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (lease_key, lease_type, provider_id, owner_token, expires_at, created_at)
            VALUES (%s, 'lock', NULL, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                owner_token = IF(expires_at <= %s, %s, owner_token),
                expires_at = IF(expires_at <= %s, %s, expires_at),
                created_at = IF(expires_at <= %s, %s, created_at)",
            $leaseKey,
            $token,
            $this->formatTime($expiresAt),
            $this->formatTime($now),
            $this->formatTime($now),
            $token,
            $this->formatTime($now),
            $this->formatTime($expiresAt),
            $this->formatTime($now),
            $this->formatTime($now)
        );
        $wpdb->query($sql);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT owner_token, expires_at FROM {$table} WHERE lease_key = %s LIMIT 1",
                $leaseKey
            ),
            ARRAY_A
        );

        if ( ! is_array($row) || ! hash_equals($token, (string) ($row['owner_token'] ?? ''))) {
            return null;
        }

        $rowExpiry = strtotime( (string) ($row['expires_at'] ?? ''));

        return is_int($rowExpiry) && $rowExpiry > $now ? $token : null;
    }

    public function reserveProvider(int $providerId, int $ttl): ?string
    {
        if ($providerId <= 0) {
            return null;
        }

        $token = $this->newToken();
        $now = $this->now();
        $expiresAt = $now + max(1, $ttl);
        $this->pruneExpired($now);

        global $wpdb;

        $table = TableNames::quotaLeases();
        $leaseKey = $this->reservationKey($providerId, $token);
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (lease_key, lease_type, provider_id, owner_token, expires_at, created_at)
                VALUES (%s, 'reservation', %d, %s, %s, %s)",
                $leaseKey,
                $providerId,
                $token,
                $this->formatTime($expiresAt),
                $this->formatTime($now)
            )
        );

        return is_numeric($inserted) && (int) $inserted > 0 ? $token : null;
    }

    public function releaseLock(string $leaseKey, string $ownerToken): bool
    {
        return $this->release($leaseKey, $ownerToken);
    }

    public function releaseProviderReservation(int $providerId, string $ownerToken): bool
    {
        if ($providerId <= 0 || $ownerToken === '') {
            return false;
        }

        return $this->release($this->reservationKey($providerId, $ownerToken), $ownerToken);
    }

    public function countReservations(int $providerId): int
    {
        if ($providerId <= 0) {
            return 0;
        }

        global $wpdb;

        $table = TableNames::quotaLeases();
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                WHERE lease_type = 'reservation' AND provider_id = %d AND expires_at > %s",
                $providerId,
                $this->formatTime($this->now())
            )
        );

        return max(0, (int) $count);
    }

    private function release(string $leaseKey, string $ownerToken): bool
    {
        global $wpdb;

        $table = TableNames::quotaLeases();
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE lease_key = %s AND owner_token = %s",
                $leaseKey,
                $ownerToken
            )
        );

        return is_numeric($deleted) && (int) $deleted > 0;
    }

    private function pruneExpired(int $now): void
    {
        global $wpdb;

        $table = TableNames::quotaLeases();
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at <= %s LIMIT %d",
                $this->formatTime($now),
                self::CLEANUP_BATCH
            )
        );
    }

    private function reservationKey(int $providerId, string $token): string
    {
        return 'provider_quota_reservation_' . $providerId . '_' . $token;
    }

    private function newToken(): string
    {
        $token = trim( (string) call_user_func($this->tokenGenerator));

        return $token !== '' ? $token : bin2hex(random_bytes(32));
    }

    private function now(): int
    {
        return max(1, (int) call_user_func($this->clock));
    }

    private function formatTime(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}

// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
