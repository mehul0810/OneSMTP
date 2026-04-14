<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Core\TableNames;

final class MessageRepository
{
    public function create(array $mailArgs, int $maxAttempts = 6, ?string $messageUuid = null): int
    {
        global $wpdb;

        if ($messageUuid === null || $messageUuid === '') {
            $messageUuid = (string) wp_generate_uuid4();
        }

        $inserted = $wpdb->insert(
            TableNames::messages(),
            [
                'message_uuid'          => $messageUuid,
                'subject'               => isset($mailArgs['subject']) ? (string) $mailArgs['subject'] : null,
                'recipients_hash'       => hash('sha256', wp_json_encode($mailArgs['to'] ?? [])),
                'body_hash'             => hash('sha256', (string) ($mailArgs['message'] ?? '')),
                'payload_json'          => wp_json_encode($mailArgs),
                'status'                => 'queued',
                'selected_provider_id'  => null,
                'current_attempt'       => 0,
                'max_attempts'          => $maxAttempts,
                'next_retry_at'         => null,
                'created_at'            => current_time('mysql', true),
                'updated_at'            => current_time('mysql', true),
            ],
            [
                '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s',
            ]
        );

        if ($inserted === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function find(int $messageId): ?array
    {
        global $wpdb;

        $sql = $wpdb->prepare('SELECT * FROM ' . TableNames::messages() . ' WHERE id = %d', $messageId);
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function findByUuid(string $messageUuid): ?array
    {
        global $wpdb;

        $sql = $wpdb->prepare('SELECT * FROM ' . TableNames::messages() . ' WHERE message_uuid = %s', $messageUuid);
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function findMostRecentByHashes(string $recipientsHash, string $bodyHash): ?array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT * FROM ' . TableNames::messages() . ' WHERE recipients_hash = %s AND body_hash = %s ORDER BY id DESC LIMIT 1',
            $recipientsHash,
            $bodyHash
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function getPayloadForMessage(int $messageId): array
    {
        $row = $this->find($messageId);
        if (! is_array($row)) {
            return [];
        }

        $payload = isset($row['payload_json']) ? json_decode((string) $row['payload_json'], true) : [];

        return is_array($payload) ? $payload : [];
    }

    public function updatePayload(int $messageId, array $payload): void
    {
        global $wpdb;

        $wpdb->update(
            TableNames::messages(),
            [
                'payload_json' => wp_json_encode($payload),
                'updated_at'   => current_time('mysql', true),
            ],
            ['id' => $messageId],
            ['%s', '%s'],
            ['%d']
        );
    }

    public function markSent(int $messageId, ?int $providerId): void
    {
        global $wpdb;

        $wpdb->update(
            TableNames::messages(),
            [
                'status'               => 'sent',
                'selected_provider_id' => $providerId,
                'next_retry_at'        => null,
                'updated_at'           => current_time('mysql', true),
            ],
            ['id' => $messageId],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public function markRetryScheduled(int $messageId, int $attempt, int $retryTimestamp): void
    {
        global $wpdb;

        $wpdb->update(
            TableNames::messages(),
            [
                'status'          => 'retry_scheduled',
                'current_attempt' => $attempt,
                'next_retry_at'   => gmdate('Y-m-d H:i:s', $retryTimestamp),
                'updated_at'      => current_time('mysql', true),
            ],
            ['id' => $messageId],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public function markRetryRunning(int $messageId, int $attempt, ?int $providerId): void
    {
        global $wpdb;

        $wpdb->update(
            TableNames::messages(),
            [
                'status'               => 'retrying',
                'current_attempt'      => $attempt,
                'selected_provider_id' => $providerId,
                'next_retry_at'        => null,
                'updated_at'           => current_time('mysql', true),
            ],
            ['id' => $messageId],
            ['%s', '%d', '%d', '%s', '%s'],
            ['%d']
        );
    }

    public function markFailedTerminal(int $messageId, int $attempt): void
    {
        global $wpdb;

        $wpdb->update(
            TableNames::messages(),
            [
                'status'          => 'failed',
                'current_attempt' => $attempt,
                'next_retry_at'   => null,
                'updated_at'      => current_time('mysql', true),
            ],
            ['id' => $messageId],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
    }
}
