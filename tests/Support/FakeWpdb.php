<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Support;

final class FakeWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;

    /** @var array<int,array<string,mixed>> */
    public array $inserts = [];

    /** @var array<int,array<string,mixed>> */
    public array $updates = [];

    /** @var array<int,array<string,mixed>> */
    public array $deletions = [];

    /** @var array<int,string> */
    public array $queries = [];

    /** @var array<int,int|false> */
    public array $queryResults = [];

    /** @var array<int,array<string,mixed>> */
    public array $messageRowsById = [];

    /** @var array<int,array<string,mixed>> */
    public array $recentMessageRows = [];

    /** @var array<int,array<string,mixed>> */
    public array $queueMessageRows = [];

    public int $filteredMessageCount = 0;

    /** @var array<string,array<string,mixed>> */
    public array $messageRowsByUuid = [];

    /** @var array<int,array<int,array<string,mixed>>> */
    public array $attemptHistoryByMessage = [];

    /** @var array<string,array{sent_count:int,oldest_created_at:?string}> */
    public array $successfulSendWindowStatsBySince = [];

    /** @var array<string,array{attempt_count:int,oldest_created_at:?string}> */
    public array $providerAttemptWindowStatsByProviderSince = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $failureCategoryRowsBySince = [];

    /** @var array<int,string> */
    public array $attemptColumns = [
        'id',
        'message_id',
        'attempt_no',
        'provider_id',
        'trigger_type',
        'result',
        'error_code',
        'error_message',
        'failure_category',
        'latency_ms',
        'provider_message_id',
        'created_at',
    ];

    /** @var array<string,array<string,mixed>> */
    public array $dashboardActivityRowsBySince = [];

    /** @var array<string,int> */
    public array $dashboardFailoverCountsBySince = [];

    /** @var array<string,mixed>|null */
    public ?array $dashboardPendingRow = null;

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $dashboardProviderAttemptRowsBySince = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $dashboardProviderFailoverRowsBySince = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $dashboardProviderEventRowsBySince = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $advancedProviderRowsByWindow = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $advancedStatusRowsByWindow = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $advancedSubjectRowsByWindow = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $advancedTrendRowsByWindow = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $advancedFailureRowsByWindow = [];

    /** @var array<int,array<string,mixed>> */
    public array $eventRows = [];

    /** @var array<string,array{lease_type:string,provider_id:?int,owner_token:string,expires_at:string,created_at:string}> */
    public array $quotaLeaseRows = [];

    /** @var array<int,array<string,mixed>> */
    public array $eventAcknowledgementRows = [];

    /** @var array<string,mixed>|null */
    public ?array $queueDiagnosticRow = null;

    /** @var array<int,array<string,mixed>> */
    public array $activeProviders = [];

    /** @var array<int,array<string,mixed>> */
    public array $providerRowsById = [];

    /** @var array{query:string,args:array<int,mixed>}|null */
    public ?array $lastPrepared = null;

    /** @var array<int,array{query:string,args:array<int,mixed>}> */
    public array $preparedQueries = [];

    public string $last_error = '';

    public bool $failAdvancedQueries = false;

    public bool $suppressErrors = false;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function insert(string $table, array $data, array $format): int
    {
        $this->insert_id++;
        $this->inserts[] = [
            'table' => $table,
            'data' => $data,
            'format' => $format,
        ];

        return 1;
    }

    public function update(string $table, array $data, array $where, array $format, array $whereFormat): int
    {
        $this->updates[] = [
            'table' => $table,
            'data' => $data,
            'where' => $where,
            'format' => $format,
            'where_format' => $whereFormat,
        ];

        return 1;
    }

    public function delete(string $table, array $where, array $whereFormat): int
    {
        $this->deletions[] = [
            'table' => $table,
            'where' => $where,
            'where_format' => $whereFormat,
        ];

        return 1;
    }

    public function query(string $sql): int|false
    {
        if (str_contains($sql, $this->prefix . 'onesmtp_quota_leases')) {
            return $this->handleQuotaLeaseQuery($sql);
        }

        $this->queries[] = $sql;

        return $this->queryResults !== [] ? array_shift($this->queryResults) : 0;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $this->lastPrepared = [
            'query' => $query,
            'args' => $args,
        ];
        $this->preparedQueries[] = $this->lastPrepared;

        return $query;
    }

    public function get_row(string $sql, mixed $output = null): ?array
    {
        $prepared = $this->lastPrepared;
        $isPreparedQuery = is_array($prepared) && $sql === $prepared['query'];
        $query = $isPreparedQuery ? $prepared['query'] : $sql;
        $args  = $isPreparedQuery ? $prepared['args'] : [];

        if (str_contains($query, $this->prefix . 'onesmtp_quota_leases') && str_contains($query, 'owner_token')) {
            $key = (string) ($args[0] ?? '');
            $lease = $this->quotaLeaseRows[$key] ?? null;

            return is_array($lease) ? [
                'owner_token' => $lease['owner_token'],
                'expires_at' => $lease['expires_at'],
            ] : null;
        }

        if (str_contains($query, $this->prefix . 'onesmtp_messages') && str_contains($query, 'message_uuid = %s')) {
            $uuid = isset($args[0]) ? (string) $args[0] : '';

            return $this->messageRowsByUuid[$uuid] ?? null;
        }

        if (str_contains($query, $this->prefix . 'onesmtp_messages') && str_contains($query, 'WHERE id = %d')) {
            $messageId = isset($args[0]) ? (int) $args[0] : 0;

            return $this->messageRowsById[$messageId] ?? null;
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_messages')
            && str_contains($query, 'queued_count')
            && str_contains($query, 'overdue_retry_count')
        ) {
            return $this->queueDiagnosticRow;
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_attempts')
            && str_contains($query, 'sent_count')
            && str_contains($query, 'failed_count')
            && str_contains($query, 'retry_count')
        ) {
            $since = isset($args[0]) ? (string) $args[0] : '';

            return $this->dashboardActivityRowsBySince[$since] ?? [
                'sent_count' => 0,
                'failed_count' => 0,
                'retry_count' => 0,
            ];
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_messages')
            && str_contains($query, 'queued_count')
            && str_contains($query, 'retry_scheduled_count')
            && str_contains($query, "status IN ('queued', 'retry_scheduled', 'retrying')")
        ) {
            return $this->dashboardPendingRow ?? [
                'queued_count' => 0,
                'retry_scheduled_count' => 0,
                'retrying_count' => 0,
            ];
        }

        if (str_contains($query, $this->prefix . 'onesmtp_attempts') && str_contains($query, 'ORDER BY id DESC LIMIT 1')) {
            $messageId = isset($args[0]) ? (int) $args[0] : 0;
            $history   = $this->attemptHistoryByMessage[$messageId] ?? [];

            if ($history === []) {
                foreach (array_reverse($this->inserts) as $insert) {
                    if (! str_ends_with($insert['table'], 'onesmtp_attempts') || (int) ($insert['data']['message_id'] ?? 0) !== $messageId) {
                        continue;
                    }

                    return $insert['data'];
                }
            }

            return $history[0] ?? null;
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_attempts')
            && str_contains($query, 'COUNT(*) AS sent_count')
            && str_contains($query, 'MIN(created_at) AS oldest_created_at')
        ) {
            $since = isset($args[1]) ? (string) $args[1] : '';

            return $this->successfulSendWindowStatsBySince[$since] ?? [
                'sent_count' => 0,
                'oldest_created_at' => null,
            ];
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_attempts')
            && str_contains($query, 'COUNT(*) AS attempt_count')
            && str_contains($query, 'MIN(created_at) AS oldest_created_at')
            && str_contains($query, 'provider_id = %d')
        ) {
            $key = (string) ($args[0] ?? '') . '|' . (string) ($args[2] ?? '');

            return $this->providerAttemptWindowStatsByProviderSince[$key] ?? [
                'attempt_count' => 0,
                'oldest_created_at' => null,
            ];
        }

        if (str_contains($query, $this->prefix . 'onesmtp_providers') && str_contains($query, 'WHERE id = %d')) {
            $providerId = isset($args[0]) ? (int) $args[0] : 0;

            return $this->providerRowsById[$providerId] ?? null;
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_events')
            && str_contains($query, 'WHERE id = %d')
            && str_contains($query, 'event_type = %s')
        ) {
            $eventId = isset($args[0]) ? (int) $args[0] : 0;

            return $this->eventRows[$eventId] ?? null;
        }

        return null;
    }

    public function get_results(string $sql, mixed $output = null): array|null
    {
        $prepared = $this->lastPrepared;
        $isPreparedQuery = is_array($prepared) && $sql === $prepared['query'];
        $query = $isPreparedQuery ? $prepared['query'] : $sql;
        $args = $isPreparedQuery ? $prepared['args'] : [];

        if ($this->failAdvancedQueries && str_contains($query, 'created_at < %s')) {
            $this->last_error = 'synthetic advanced report query failure';

            return null;
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_attempts')
            && str_contains($query, 'attempt_count')
            && str_contains($query, 'GROUP BY COALESCE(a.provider_id, 0), p.name, p.adapter_type')
            && str_contains($query, 'LIMIT %d')
        ) {
            $key = (string) ($args[0] ?? '') . '|' . (string) ($args[1] ?? '');

            return $this->advancedProviderRowsByWindow[$key] ?? [];
        }

        if (str_contains($query, $this->prefix . 'onesmtp_messages') && str_contains($query, 'status_count')) {
            $key = (string) ($args[0] ?? '') . '|' . (string) ($args[1] ?? '');

            if (str_contains($query, 'DATE(created_at)')) {
                return $this->advancedTrendRowsByWindow[$key] ?? [];
            }

            return $this->advancedStatusRowsByWindow[$key] ?? [];
        }

        if (str_contains($query, $this->prefix . 'onesmtp_messages') && str_contains($query, 'subject_count')) {
            $key = (string) ($args[0] ?? '') . '|' . (string) ($args[1] ?? '');

            return $this->advancedSubjectRowsByWindow[$key] ?? [];
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_attempts')
            && str_contains($query, 'failure_count')
            && str_contains($query, 'last_seen_at')
            && str_contains($query, 'created_at < %s')
            && str_contains($query, 'LIMIT %d')
        ) {
            $key = (string) ($args[1] ?? '') . '|' . (string) ($args[2] ?? '');

            return $this->advancedFailureRowsByWindow[$key] ?? [];
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_attempts')
            && str_contains($query, 'provider_name')
            && str_contains($query, 'retry_count')
            && str_contains($query, 'GROUP BY COALESCE(a.provider_id, 0)')
        ) {
            $since = isset($args[0]) ? (string) $args[0] : '';

            return $this->dashboardProviderAttemptRowsBySince[$since] ?? [];
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_events')
            && str_contains($query, 'failover_count')
            && str_contains($query, 'GROUP BY COALESCE(e.provider_id, 0)')
        ) {
            $since = isset($args[1]) ? (string) $args[1] : '';

            return $this->dashboardProviderFailoverRowsBySince[$since] ?? [];
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_events')
            && str_contains($query, 'context_json')
            && str_contains($query, 'event_type = %s')
            && ($args[0] ?? '') === 'provider_failover'
        ) {
            $since = isset($args[1]) ? (string) $args[1] : '';

            return $this->dashboardProviderEventRowsBySince[$since] ?? [];
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_events')
            && ($args[0] ?? '') === 'audit_alert_acknowledged'
            && str_contains($query, 'ORDER BY id DESC')
        ) {
            return $this->eventAcknowledgementRows;
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_events')
            && str_contains($query, "event_type IN")
            && str_contains($query, 'ORDER BY id DESC')
        ) {
            return array_values($this->eventRows);
        }

        if (str_contains($sql, $this->prefix . 'onesmtp_providers') && ! str_contains($sql, 'JOIN')) {
            return $this->activeProviders;
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_attempts')
            && str_contains($query, 'ORDER BY id DESC LIMIT 6')
        ) {
            $messageId = isset($args[0]) ? (int) $args[0] : 0;
            if (isset($this->attemptHistoryByMessage[$messageId])) {
                return $this->attemptHistoryByMessage[$messageId];
            }

            $rows = [];
            foreach (array_reverse($this->inserts) as $insert) {
                if (str_ends_with($insert['table'], 'onesmtp_attempts') && (int) ($insert['data']['message_id'] ?? 0) === $messageId) {
                    $rows[] = $insert['data'];
                }
            }

            return array_slice($rows, 0, 6);
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_messages')
            && str_contains($query, 'queue_attempt_count')
        ) {
            return $this->queueMessageRows;
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_messages')
            && str_contains($query, 'FROM ' . $this->prefix . 'onesmtp_attempts')
            && str_contains($query, 'attempt_count')
        ) {
            return $this->recentMessageRows;
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_attempts')
            && str_contains($query, 'ORDER BY attempt_no ASC, id ASC')
        ) {
            $messageId = isset($args[0]) ? (int) $args[0] : 0;

            return $this->attemptHistoryByMessage[$messageId] ?? [];
        }

        if (
            str_contains($query, $this->prefix . 'onesmtp_attempts')
            && str_contains($query, 'failure_category')
            && str_contains($query, 'failure_count')
            && str_contains($query, 'GROUP BY')
        ) {
            if (! in_array('failure_category', $this->attemptColumns, true)) {
                return [];
            }

            $since = isset($args[1]) ? (string) $args[1] : '';

            return $this->failureCategoryRowsBySince[$since] ?? [];
        }

        return [];
    }

    public function get_var(string $sql): int
    {
        $prepared = $this->lastPrepared;
        $preparedQuery = is_array($prepared) ? (string) ($prepared['query'] ?? '') : '';
        $preparedArgs = is_array($prepared) && isset($prepared['args']) && is_array($prepared['args']) ? $prepared['args'] : [];
        if (str_contains($preparedQuery, $this->prefix . 'onesmtp_quota_leases') && str_contains($preparedQuery, 'COUNT(*)')) {
            $providerId = (int) ($preparedArgs[0] ?? 0);
            $now = strtotime((string) ($preparedArgs[1] ?? '')) ?: 0;
            $count = 0;
            foreach ($this->quotaLeaseRows as $lease) {
                if ($lease['lease_type'] !== 'reservation' || (int) $lease['provider_id'] !== $providerId) {
                    continue;
                }

                $expiresAt = strtotime($lease['expires_at']) ?: 0;
                if ($expiresAt > $now) {
                    $count++;
                }
            }

            return $count;
        }

        if (
            str_contains($sql, $this->prefix . 'onesmtp_messages')
            && str_contains($sql, 'SELECT COUNT(*)')
        ) {
            return $this->filteredMessageCount > 0 ? $this->filteredMessageCount : count($this->recentMessageRows);
        }

        $prepared = $this->lastPrepared;
        if (! is_array($prepared)) {
            return 0;
        }

        if (
            str_contains($prepared['query'], $this->prefix . 'onesmtp_messages')
            && str_contains($prepared['query'], 'SELECT COUNT(*)')
        ) {
            return $this->filteredMessageCount > 0 ? $this->filteredMessageCount : count($this->recentMessageRows);
        }

        if (
            str_contains($prepared['query'], $this->prefix . 'onesmtp_events')
            && str_contains($prepared['query'], 'SELECT COUNT(*)')
        ) {
            $since = isset($prepared['args'][1]) ? (string) $prepared['args'][1] : '';

            return $this->dashboardFailoverCountsBySince[$since] ?? 0;
        }

        if (
            str_contains($prepared['query'], $this->prefix . 'onesmtp_attempts')
            && str_contains($prepared['query'], 'SELECT COUNT(*)')
        ) {
            $messageId = isset($prepared['args'][0]) ? (int) $prepared['args'][0] : 0;

            return count($this->attemptHistoryByMessage[$messageId] ?? []);
        }

        return 0;
    }

    private function handleQuotaLeaseQuery(string $sql): int
    {
        $prepared = $this->lastPrepared;
        $isPreparedQuery = is_array($prepared) && $sql === $prepared['query'];
        $query = $isPreparedQuery ? $prepared['query'] : $sql;
        $args = $isPreparedQuery && is_array($prepared['args']) ? $prepared['args'] : [];

        if (str_contains($query, 'DELETE FROM') && str_contains($query, 'expires_at <=')) {
            $now = strtotime((string) ($args[0] ?? '')) ?: 0;
            $limit = max(1, (int) ($args[1] ?? 100));
            $deleted = 0;
            foreach (array_keys($this->quotaLeaseRows) as $key) {
                if ($deleted >= $limit) {
                    break;
                }

                $expiresAt = strtotime($this->quotaLeaseRows[$key]['expires_at']) ?: 0;
                if ($expiresAt <= $now) {
                    unset($this->quotaLeaseRows[$key]);
                    $deleted++;
                }
            }

            return $deleted;
        }

        if (str_contains($query, 'DELETE FROM') && str_contains($query, 'owner_token =')) {
            $key = (string) ($args[0] ?? '');
            $token = (string) ($args[1] ?? '');
            if (! isset($this->quotaLeaseRows[$key]) || $this->quotaLeaseRows[$key]['owner_token'] !== $token) {
                return 0;
            }

            unset($this->quotaLeaseRows[$key]);

            return 1;
        }

        if (str_contains($query, 'ON DUPLICATE KEY UPDATE')) {
            $key = (string) ($args[0] ?? '');
            $now = strtotime((string) ($args[4] ?? '')) ?: 0;
            if (isset($this->quotaLeaseRows[$key])) {
                $existingExpiry = strtotime($this->quotaLeaseRows[$key]['expires_at']) ?: 0;
                if ($existingExpiry <= $now) {
                    $this->quotaLeaseRows[$key]['owner_token'] = (string) ($args[5] ?? '');
                    $this->quotaLeaseRows[$key]['expires_at'] = (string) ($args[7] ?? '');
                    $this->quotaLeaseRows[$key]['created_at'] = (string) ($args[9] ?? '');

                    return 2;
                }

                return 0;
            }

            $this->quotaLeaseRows[$key] = [
                'lease_type' => 'lock',
                'provider_id' => null,
                'owner_token' => (string) ($args[1] ?? ''),
                'expires_at' => (string) ($args[2] ?? ''),
                'created_at' => (string) ($args[3] ?? ''),
            ];

            return 1;
        }

        if (str_contains($query, 'INSERT INTO')) {
            $key = (string) ($args[0] ?? '');
            if (isset($this->quotaLeaseRows[$key])) {
                return 0;
            }

            $this->quotaLeaseRows[$key] = [
                'lease_type' => 'reservation',
                'provider_id' => (int) ($args[1] ?? 0),
                'owner_token' => (string) ($args[2] ?? ''),
                'expires_at' => (string) ($args[3] ?? ''),
                'created_at' => (string) ($args[4] ?? ''),
            ];

            return 1;
        }

        return 0;
    }

    public function suppress_errors(?bool $suppress = null): bool
    {
        $previous = $this->suppressErrors;

        if ($suppress !== null) {
            $this->suppressErrors = $suppress;
        }

        return $previous;
    }
}
