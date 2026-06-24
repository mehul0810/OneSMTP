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

    public function listRecent(int $limit = 50): array
    {
        global $wpdb;

        $limit = max(1, min(200, $limit));
        $sql = $wpdb->prepare(
            'SELECT id, message_uuid, subject, recipients_hash, status, selected_provider_id, current_attempt, max_attempts, next_retry_at, created_at, updated_at FROM ' . TableNames::messages() . ' ORDER BY id DESC LIMIT %d',
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function listRecentWithAttemptCounts(int $limit = 50): array
    {
        global $wpdb;

        $limit = max(1, min(200, $limit));
        $messagesTable = TableNames::messages();
        $attemptsTable = TableNames::attempts();
        $sql = $wpdb->prepare(
            "SELECT m.id, m.message_uuid, m.recipients_hash, m.payload_json, m.status, m.selected_provider_id, m.current_attempt, m.max_attempts, m.next_retry_at, m.created_at, m.updated_at, COALESCE(a.attempt_count, 0) AS attempt_count
            FROM {$messagesTable} m
            LEFT JOIN (
                SELECT message_id, COUNT(id) AS attempt_count
                FROM {$attemptsTable}
                GROUP BY message_id
            ) a ON a.message_id = m.id
            ORDER BY m.id DESC
            LIMIT %d",
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function listFilteredWithAttemptCounts(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        global $wpdb;

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $messagesTable = TableNames::messages();
        $attemptsTable = TableNames::attempts();
        [$whereSql, $args] = $this->buildLogFilterWhere($filters, 'm');

        $sql = "SELECT m.id, m.message_uuid, m.recipients_hash, m.payload_json, m.status, m.selected_provider_id, m.current_attempt, m.max_attempts, m.next_retry_at, m.created_at, m.updated_at, COALESCE(a.attempt_count, 0) AS attempt_count
            FROM {$messagesTable} m
            LEFT JOIN (
                SELECT message_id, COUNT(id) AS attempt_count
                FROM {$attemptsTable}
                GROUP BY message_id
            ) a ON a.message_id = m.id
            {$whereSql}
            ORDER BY m.id DESC
            LIMIT %d OFFSET %d";
        $args[] = $perPage;
        $args[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function countFiltered(array $filters = []): int
    {
        global $wpdb;

        $messagesTable = TableNames::messages();
        [$whereSql, $args] = $this->buildLogFilterWhere($filters, 'm');

        $sql = "SELECT COUNT(*) FROM {$messagesTable} m {$whereSql}";
        $prepared = $args === [] ? $sql : $wpdb->prepare($sql, ...$args);

        return max(0, (int) $wpdb->get_var($prepared));
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildLogFilterWhere(array $filters, string $alias): array
    {
        $clauses = [];
        $args = [];
        $prefix = $alias !== '' ? $alias . '.' : '';

        $status = isset($filters['status']) ? sanitize_key((string) $filters['status']) : '';
        if ($status !== '') {
            $clauses[] = "{$prefix}status = %s";
            $args[] = $status;
        }

        $providerId = isset($filters['provider_id']) ? (int) $filters['provider_id'] : 0;
        if ($providerId > 0) {
            $clauses[] = "{$prefix}selected_provider_id = %d";
            $args[] = $providerId;
        }

        $dateFrom = isset($filters['date_from']) ? (string) $filters['date_from'] : '';
        if ($this->isDateString($dateFrom)) {
            $clauses[] = "{$prefix}created_at >= %s";
            $args[] = $dateFrom . ' 00:00:00';
        }

        $dateTo = isset($filters['date_to']) ? (string) $filters['date_to'] : '';
        if ($this->isDateString($dateTo)) {
            $clauses[] = "{$prefix}created_at <= %s";
            $args[] = $dateTo . ' 23:59:59';
        }

        $recipientHash = isset($filters['recipient_hash']) ? strtolower(trim((string) $filters['recipient_hash'])) : '';
        if ($this->isSha256Hash($recipientHash)) {
            $clauses[] = "{$prefix}recipients_hash = %s";
            $args[] = $recipientHash;
        }

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $searchClauses = ["{$prefix}message_uuid LIKE %s"];
            $searchArgs = ['%' . $this->escapeLike($search) . '%'];

            $normalizedSearch = strtolower($search);
            if ($this->isSha256Hash($normalizedSearch)) {
                $searchClauses[] = "{$prefix}recipients_hash = %s";
                $searchArgs[] = $normalizedSearch;
            }

            if (ctype_digit($search)) {
                $searchClauses[] = "{$prefix}id = %d";
                $searchArgs[] = (int) $search;
            }

            $clauses[] = '(' . implode(' OR ', $searchClauses) . ')';
            array_push($args, ...$searchArgs);
        }

        return [$clauses !== [] ? 'WHERE ' . implode(' AND ', $clauses) : '', $args];
    }

    private function isDateString(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function isSha256Hash(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '_%\\');
    }

    /**
     * @return array{queued_count:int,retry_scheduled_count:int,retrying_count:int,failed_count:int,overdue_retry_count:int,next_retry_at:?string}
     */
    public function getQueueStatusSummary(string $overdueBefore): array
    {
        global $wpdb;

        $messagesTable = TableNames::messages();
        $sql = $wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END), 0) AS queued_count,
                COALESCE(SUM(CASE WHEN status = 'retry_scheduled' THEN 1 ELSE 0 END), 0) AS retry_scheduled_count,
                COALESCE(SUM(CASE WHEN status = 'retrying' THEN 1 ELSE 0 END), 0) AS retrying_count,
                COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN status = 'retry_scheduled' AND next_retry_at IS NOT NULL AND next_retry_at < %s THEN 1 ELSE 0 END), 0) AS overdue_retry_count,
                MIN(CASE WHEN status = 'retry_scheduled' THEN next_retry_at ELSE NULL END) AS next_retry_at
            FROM {$messagesTable}",
            $overdueBefore
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        if (! is_array($row)) {
            $row = [];
        }

        return [
            'queued_count'          => (int) ($row['queued_count'] ?? 0),
            'retry_scheduled_count' => (int) ($row['retry_scheduled_count'] ?? 0),
            'retrying_count'        => (int) ($row['retrying_count'] ?? 0),
            'failed_count'          => (int) ($row['failed_count'] ?? 0),
            'overdue_retry_count'   => (int) ($row['overdue_retry_count'] ?? 0),
            'next_retry_at'         => isset($row['next_retry_at']) && (string) $row['next_retry_at'] !== '' ? (string) $row['next_retry_at'] : null,
        ];
    }
}
