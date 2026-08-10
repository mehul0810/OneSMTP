<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Core\TableNames;
use OneSMTP\Events\ProviderEvent;
use OneSMTP\Events\ProviderEventStoreResult;

final class ProviderEventRepository
{
    /*
     * The table name is plugin-owned. All event values use prepared SQL and
     * the unique hash is the durable idempotency boundary for webhook races.
     */
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    public function findMessageId(int $providerId, ?string $providerMessageId): ?int
    {
        if ($providerId <= 0 || $providerMessageId === null || trim($providerMessageId) === '') {
            return null;
        }

        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT message_id FROM ' . TableNames::attempts() . ' WHERE provider_id = %d AND provider_message_id = %s ORDER BY id DESC LIMIT 1',
            $providerId,
            $providerMessageId
        );
        $messageId = $wpdb->get_var($sql);

        return is_numeric($messageId) && (int) $messageId > 0 ? (int) $messageId : null;
    }

    public function record(ProviderEvent $event, ?int $providerId, ?int $messageId): ProviderEventStoreResult
    {
        global $wpdb;

        $externalHash = self::externalEventHash($event);
        $occurredAt = $event->getOccurredAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $providerValue = $providerId !== null && $providerId > 0 ? '%d' : 'NULL';
        $messageValue = $messageId !== null && $messageId > 0 ? '%d' : 'NULL';
        $args = [
            $event->getProvider(),
        ];
        if ($providerValue === '%d') {
            $args[] = $providerId;
        }
        if ($messageValue === '%d') {
            $args[] = $messageId;
        }
        $args = array_merge($args, [
            $event->getProviderMessageId(),
            $event->getType()->value,
            $occurredAt,
            $externalHash,
            $event->getRecipientFingerprint(),
            current_time('mysql', true),
        ]);
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Provider/message placeholders are conditional and args are assembled above.
        $sql = $wpdb->prepare(
            'INSERT INTO ' . TableNames::providerEvents() . ' (provider, provider_id, message_id, provider_message_id, event_type, occurred_at, external_event_hash, recipient_fingerprint, created_at) VALUES (%s, ' . $providerValue . ', ' . $messageValue . ', %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE id = id',
            ...$args
        );

        if ( ! is_string($sql) ) {
            return ProviderEventStoreResult::FAILED;
        }

        $result = $wpdb->query($sql);
        if ($result === false) {
            return ProviderEventStoreResult::FAILED;
        }

        return (int) $result > 0 ? ProviderEventStoreResult::INSERTED : ProviderEventStoreResult::DUPLICATE;
    }

    public static function externalEventHash(ProviderEvent $event): string
    {
        return hash('sha256', $event->getProvider() . "\0" . $event->getEventId());
    }
}
