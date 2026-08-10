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

    public function record(ProviderEvent $event, ?int $providerId, ?int $messageId, string $replayTokenHash): ProviderEventStoreResult
    {
        global $wpdb;

        if (preg_match('/\A[a-f0-9]{64}\z/D', $replayTokenHash) !== 1) {
            return ProviderEventStoreResult::FAILED;
        }

        $externalHash = self::externalEventHash($event);
        $eventDataHash = self::eventDataHash($event);
        $occurredAt = $event->getOccurredAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $providerValue = $providerId !== null && $providerId > 0 ? '%d' : 'NULL';
        $messageValue = $messageId !== null && $messageId > 0 ? '%d' : 'NULL';
        $providerMessageValue = $event->getProviderMessageId() === null ? 'NULL' : '%s';
        $recipientValue = $event->getRecipientFingerprint() === null ? 'NULL' : '%s';

        $replayClaim = $this->claimReplayToken($event, $externalHash, $eventDataHash, $replayTokenHash);
        if ($replayClaim === ProviderEventStoreResult::FAILED) {
            return ProviderEventStoreResult::FAILED;
        }

        $args = [
            $event->getProvider(),
        ];
        if ($providerValue === '%d') {
            $args[] = $providerId;
        }
        if ($messageValue === '%d') {
            $args[] = $messageId;
        }
        if ($providerMessageValue === '%s') {
            $args[] = $event->getProviderMessageId();
        }
        $args[] = $event->getType()->value;
        $args[] = $occurredAt;
        $args[] = $externalHash;
        $args[] = $eventDataHash;
        $args[] = $replayTokenHash;
        if ($recipientValue === '%s') {
            $args[] = $event->getRecipientFingerprint();
        }
        $args[] = current_time('mysql', true);
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Provider/message placeholders are conditional and args are assembled above.
        $sql = $wpdb->prepare(
            'INSERT INTO ' . TableNames::providerEvents() . ' (provider, provider_id, message_id, provider_message_id, event_type, occurred_at, external_event_hash, event_data_hash, replay_token_hash, recipient_fingerprint, created_at) VALUES (%s, ' . $providerValue . ', ' . $messageValue . ', ' . $providerMessageValue . ', %s, %s, %s, %s, %s, ' . $recipientValue . ', %s) ON DUPLICATE KEY UPDATE id = id',
            ...$args
        );

        if ( ! is_string($sql) ) {
            return ProviderEventStoreResult::FAILED;
        }

        $result = $wpdb->query($sql);
        if ($result === false) {
            return ProviderEventStoreResult::FAILED;
        }

        if ( (int) $result > 0 ) {
            return ProviderEventStoreResult::INSERTED;
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT external_event_hash, event_data_hash FROM ' . TableNames::providerEvents() . ' WHERE replay_token_hash = %s OR external_event_hash = %s ORDER BY id ASC LIMIT 1',
                $replayTokenHash,
                $externalHash
            ),
            ARRAY_A
        );
        $existingEventDataHash = is_array($existing) ? (string) ($existing['event_data_hash'] ?? '') : '';
        if ( ! is_array($existing) || ($existingEventDataHash !== '' && ! hash_equals($existingEventDataHash, $eventDataHash)) ) {
            return ProviderEventStoreResult::FAILED;
        }

        return ProviderEventStoreResult::DUPLICATE;
    }

    private function claimReplayToken(ProviderEvent $event, string $externalHash, string $eventDataHash, string $replayTokenHash): ProviderEventStoreResult
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            'INSERT INTO ' . TableNames::providerEventReplays() . ' (provider, replay_token_hash, event_data_hash, external_event_hash, created_at) VALUES (%s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE id = id',
            $event->getProvider(),
            $replayTokenHash,
            $eventDataHash,
            $externalHash,
            current_time('mysql', true)
        );
        if ( ! is_string($sql) ) {
            return ProviderEventStoreResult::FAILED;
        }

        $result = $wpdb->query($sql);
        if ($result === false) {
            return ProviderEventStoreResult::FAILED;
        }
        if ( (int) $result > 0 ) {
            return ProviderEventStoreResult::INSERTED;
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT event_data_hash FROM ' . TableNames::providerEventReplays() . ' WHERE replay_token_hash = %s LIMIT 1',
                $replayTokenHash
            ),
            ARRAY_A
        );
        $existingEventDataHash = is_array($existing) ? (string) ($existing['event_data_hash'] ?? '') : '';
        if ( ! is_array($existing) || ! hash_equals($existingEventDataHash, $eventDataHash)) {
            return ProviderEventStoreResult::FAILED;
        }

        return ProviderEventStoreResult::DUPLICATE;
    }

    public function backfillMessageId(int $providerId, string $providerMessageId, int $messageId): void
    {
        if ($providerId <= 0 || $messageId <= 0 || trim($providerMessageId) === '') {
            return;
        }

        global $wpdb;

        $sql = $wpdb->prepare(
            'UPDATE ' . TableNames::providerEvents() . ' SET message_id = %d WHERE provider_id = %d AND provider_message_id = %s AND message_id IS NULL ORDER BY id DESC LIMIT 25',
            $messageId,
            $providerId,
            $providerMessageId
        );
        if (is_string($sql)) {
            $wpdb->query($sql);
        }
    }

    public static function externalEventHash(ProviderEvent $event): string
    {
        return hash('sha256', $event->getProvider() . "\0" . $event->getEventId());
    }

    public static function eventDataHash(ProviderEvent $event): string
    {
        return hash(
            'sha256',
            implode(
                "\0",
                [
                    $event->getProvider(),
                    $event->getEventId(),
                    $event->getType()->value,
                    $event->getOccurredAt()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
                    (string) ($event->getProviderMessageId() ?? ''),
                    (string) ($event->getRecipientFingerprint() ?? ''),
                    (string) ($event->getRecipientDomain() ?? ''),
                ]
            )
        );
    }
}
