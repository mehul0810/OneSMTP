<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Core\TableNames;

final class AttemptRepository
{
    public function add(array $data): int
    {
        global $wpdb;

        $inserted = $wpdb->insert(
            TableNames::attempts(),
            [
                'message_id'           => (int) $data['message_id'],
                'attempt_no'           => (int) $data['attempt_no'],
                'provider_id'          => isset($data['provider_id']) ? (int) $data['provider_id'] : null,
                'trigger_type'         => (string) ($data['trigger_type'] ?? 'initial'),
                'result'               => (string) ($data['result'] ?? 'fail'),
                'error_code'           => isset($data['error_code']) ? (string) $data['error_code'] : null,
                'error_message'        => isset($data['error_message']) ? (string) $data['error_message'] : null,
                'failure_category'     => isset($data['failure_category']) ? (string) $data['failure_category'] : null,
                'latency_ms'           => isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
                'provider_message_id'  => isset($data['provider_message_id']) ? (string) $data['provider_message_id'] : null,
                'created_at'           => current_time('mysql', true),
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public function getAttemptCountForMessage(int $messageId): int
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . TableNames::attempts() . ' WHERE message_id = %d',
            $messageId
        );

        return (int) $wpdb->get_var($sql);
    }

    public function getLastAttemptForMessage(int $messageId): ?array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT * FROM ' . TableNames::attempts() . ' WHERE message_id = %d ORDER BY id DESC LIMIT 1',
            $messageId
        );

        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function countConsecutiveFailuresForProvider(int $messageId, int $providerId): int
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT result, provider_id FROM ' . TableNames::attempts() . ' WHERE message_id = %d ORDER BY id DESC LIMIT 6',
            $messageId
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (! is_array($rows) || $rows === []) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            if ((int) ($row['provider_id'] ?? 0) !== $providerId) {
                break;
            }

            if (($row['result'] ?? '') !== 'fail') {
                break;
            }

            $count++;
        }

        return $count;
    }

    public function listByMessageId(int $messageId): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT * FROM ' . TableNames::attempts() . ' WHERE message_id = %d ORDER BY attempt_no ASC, id ASC',
            $messageId
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{sent_count:int,oldest_created_at:?string}
     */
    public function getSuccessfulSendWindowStats(string $since): array
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            'SELECT COUNT(*) AS sent_count, MIN(created_at) AS oldest_created_at FROM ' . TableNames::attempts() . ' WHERE result = %s AND created_at >= %s',
            'sent',
            $since
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        if (! is_array($row)) {
            $row = [];
        }

        return [
            'sent_count'        => (int) ($row['sent_count'] ?? 0),
            'oldest_created_at' => isset($row['oldest_created_at']) && (string) $row['oldest_created_at'] !== '' ? (string) $row['oldest_created_at'] : null,
        ];
    }

    /**
     * @return array<int,array{category:string,count:int,last_seen_at:?string}>
     */
    public function getRecentFailureCategoryCounts(string $since, int $limit = 10): array
    {
        global $wpdb;

        $limit = max(1, min(20, $limit));
        $sql = $wpdb->prepare(
            "SELECT
                COALESCE(NULLIF(failure_category, ''), 'uncategorized') AS failure_category,
                COUNT(*) AS failure_count,
                MAX(created_at) AS last_seen_at
            FROM " . TableNames::attempts() . '
            WHERE result = %s AND created_at >= %s
            GROUP BY COALESCE(NULLIF(failure_category, \'\'), \'uncategorized\')
            ORDER BY failure_count DESC, failure_category ASC
            LIMIT %d',
            'fail',
            $since,
            $limit
        );
        $previousSuppressErrors = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $wpdb->suppress_errors($previousSuppressErrors);

        if (! is_array($rows)) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'category' => sanitize_key((string) ($row['failure_category'] ?? 'uncategorized')),
                'count' => (int) ($row['failure_count'] ?? 0),
                'last_seen_at' => isset($row['last_seen_at']) && (string) $row['last_seen_at'] !== '' ? (string) $row['last_seen_at'] : null,
            ],
            $rows
        );
    }
}
