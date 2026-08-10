<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Analytics\SubjectGroupFormatter;
use OneSMTP\Core\TableNames;

final class MetricsRepository
{
    private const SUBJECT_CANDIDATE_MULTIPLIER = 10;
    private const SUBJECT_CANDIDATE_MAX = 500;

    /*
     * Repository queries use only plugin-owned identifiers from TableNames.
     * Every runtime value is passed through wpdb::prepare() before execution.
     * Plugin Check cannot follow that invariant across the TableNames helper
     * and intermediate prepared SQL variables.
     */
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    private SubjectGroupFormatter $subjectGroups;

    public function __construct(?SubjectGroupFormatter $subjectGroups = null)
    {
        $this->subjectGroups = $subjectGroups ?? new SubjectGroupFormatter();
    }

    /**
     * @return array{sent_count:int,failed_count:int,retry_count:int,failover_count:int}
     */
    public function getActivityWindowSummary(string $since): array
    {
        global $wpdb;

        $attemptsTable = TableNames::attempts();
        $sql = $wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN result = 'sent' THEN 1 ELSE 0 END), 0) AS sent_count,
                COALESCE(SUM(CASE WHEN result = 'fail' THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN trigger_type = 'retry' THEN 1 ELSE 0 END), 0) AS retry_count
            FROM {$attemptsTable}
            WHERE created_at >= %s",
            $since
        );
        $attemptRow = $wpdb->get_row($sql, ARRAY_A);
        $attemptRow = is_array($attemptRow) ? $attemptRow : [];

        $eventsTable = TableNames::events();
        $failoverSql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$eventsTable} WHERE event_type = %s AND created_at >= %s",
            'provider_failover',
            $since
        );

        return [
            'sent_count'     => (int) ($attemptRow['sent_count'] ?? 0),
            'failed_count'   => (int) ($attemptRow['failed_count'] ?? 0),
            'retry_count'    => (int) ($attemptRow['retry_count'] ?? 0),
            'failover_count' => max(0, (int) $wpdb->get_var($failoverSql)),
        ];
    }

    /**
     * @return array{queued_count:int,retry_scheduled_count:int,retrying_count:int,total_pending_count:int}
     */
    public function getPendingSummary(): array
    {
        global $wpdb;

        $messagesTable = TableNames::messages();
        $sql = "SELECT
                COALESCE(SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END), 0) AS queued_count,
                COALESCE(SUM(CASE WHEN status = 'retry_scheduled' THEN 1 ELSE 0 END), 0) AS retry_scheduled_count,
                COALESCE(SUM(CASE WHEN status = 'retrying' THEN 1 ELSE 0 END), 0) AS retrying_count
            FROM {$messagesTable}
            WHERE status IN ('queued', 'retry_scheduled', 'retrying')";
        $row = $wpdb->get_row($sql, ARRAY_A);
        $row = is_array($row) ? $row : [];

        $queued = (int) ($row['queued_count'] ?? 0);
        $scheduled = (int) ($row['retry_scheduled_count'] ?? 0);
        $retrying = (int) ($row['retrying_count'] ?? 0);

        return [
            'queued_count'          => $queued,
            'retry_scheduled_count' => $scheduled,
            'retrying_count'        => $retrying,
            'total_pending_count'   => $queued + $scheduled + $retrying,
        ];
    }

    /**
     * Read the bounded Pro report slices for one validated analytics window.
     *
     * Each slice is limited independently so a high-cardinality subject or
     * status distribution cannot hydrate an unbounded admin payload. Subject
     * candidates are deliberately overfetched (up to 500) before grouping so
     * case/whitespace variants can combine, then the final limit is applied.
     * The date predicates are range predicates on indexed created_at fields;
     * no message payload or event context is selected.
     *
     * @return array{
     *     error:bool,
     *     providers:array<int,array<string,mixed>>,
     *     statuses:array<int,array{status:string,count:int}>,
     *     subjects:array<int,array{key:string,label:string,count:int}>,
     *     trend:array<int,array{period:string,status:string,count:int}>,
     *     failure_categories:array<int,array{category:string,count:int,last_seen_at:?string}>
     * }
     */
    public function getAdvancedReport(string $since, string $until, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $providerRows = $this->queryAdvancedProviderRows($since, $until, $limit);
        $statusRows = $this->queryAdvancedStatusRows($since, $until, $limit);
        $subjectRows = $this->queryAdvancedSubjectRows($since, $until, $limit);
        $trendRows = $this->queryAdvancedTrendRows($since, $until, 100);
        $failureRows = $this->queryAdvancedFailureRows($since, $until, $limit);

        return [
            'error' => $providerRows === null
                || $statusRows === null
                || $subjectRows === null
                || $trendRows === null
                || $failureRows === null,
            'providers' => $providerRows ?? [],
            'statuses' => $statusRows ?? [],
            'subjects' => $subjectRows ?? [],
            'trend' => $trendRows ?? [],
            'failure_categories' => $failureRows ?? [],
        ];
    }

    /**
     * @return array<int,array{provider_id:int,provider_name:string,adapter_type:string,sent_count:int,failed_count:int,retry_count:int,attempt_count:int,avg_latency_ms:?int,failover_count:int,switch_out_count:int,total_activity:int}|null>
     */
    private function queryAdvancedProviderRows(string $since, string $until, int $limit): ?array
    {
        global $wpdb;

        $attemptsTable = TableNames::attempts();
        $providersTable = TableNames::providers();
        $sql = $wpdb->prepare(
            "SELECT
                COALESCE(a.provider_id, 0) AS provider_id,
                COALESCE(p.name, 'Unknown provider') AS provider_name,
                COALESCE(p.adapter_type, 'unknown') AS adapter_type,
                COALESCE(SUM(CASE WHEN a.result = 'sent' THEN 1 ELSE 0 END), 0) AS sent_count,
                COALESCE(SUM(CASE WHEN a.result = 'fail' THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN a.trigger_type = 'retry' THEN 1 ELSE 0 END), 0) AS retry_count,
                COUNT(a.id) AS attempt_count,
                AVG(CASE WHEN a.latency_ms IS NOT NULL THEN a.latency_ms END) AS avg_latency_ms
            FROM {$attemptsTable} a
            LEFT JOIN {$providersTable} p ON p.id = a.provider_id
            WHERE a.created_at >= %s AND a.created_at < %s
            GROUP BY COALESCE(a.provider_id, 0), p.name, p.adapter_type
            ORDER BY attempt_count DESC, provider_name ASC
            LIMIT %d",
            $since,
            $until,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return null;
        }

        return array_map(
            static function (array $row): array {
                $sent = (int) ($row['sent_count'] ?? 0);
                $failed = (int) ($row['failed_count'] ?? 0);
                $retry = (int) ($row['retry_count'] ?? 0);

                return [
                    'provider_id' => (int) ($row['provider_id'] ?? 0),
                    'provider_name' => (string) ($row['provider_name'] ?? 'Unknown provider'),
                    'adapter_type' => (string) ($row['adapter_type'] ?? 'unknown'),
                    'sent_count' => $sent,
                    'failed_count' => $failed,
                    'retry_count' => $retry,
                    'attempt_count' => max(0, (int) ($row['attempt_count'] ?? ($sent + $failed))),
                    'avg_latency_ms' => isset($row['avg_latency_ms']) ? max(0, (int) round((float) $row['avg_latency_ms'])) : null,
                    'failover_count' => 0,
                    'switch_out_count' => 0,
                    'total_activity' => $sent + $failed + $retry,
                ];
            },
            $rows
        );
    }

    /** @return array<int,array{status:string,count:int}>|null */
    private function queryAdvancedStatusRows(string $since, string $until, int $limit): ?array
    {
        global $wpdb;

        $messagesTable = TableNames::messages();
        $sql = $wpdb->prepare(
            "SELECT COALESCE(NULLIF(status, ''), 'unknown') AS status, COUNT(*) AS status_count
            FROM {$messagesTable}
            WHERE created_at >= %s AND created_at < %s
            GROUP BY COALESCE(NULLIF(status, ''), 'unknown')
            ORDER BY status_count DESC, status ASC
            LIMIT %d",
            $since,
            $until,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return null;
        }

        return array_map(
            static fn (array $row): array => [
                'status' => sanitize_key((string) ($row['status'] ?? 'unknown')) ?: 'unknown',
                'count' => max(0, (int) ($row['status_count'] ?? 0)),
            ],
            $rows
        );
    }

    /** @return array<int,array{key:string,label:string,count:int}>|null */
    private function queryAdvancedSubjectRows(string $since, string $until, int $limit): ?array
    {
        global $wpdb;

        $candidateLimit = min(
            self::SUBJECT_CANDIDATE_MAX,
            max($limit, $limit * self::SUBJECT_CANDIDATE_MULTIPLIER)
        );
        $messagesTable = TableNames::messages();
        $sql = $wpdb->prepare(
            "SELECT COALESCE(subject, '') AS subject, COUNT(*) AS subject_count
            FROM {$messagesTable}
            WHERE created_at >= %s AND created_at < %s
            GROUP BY COALESCE(subject, '')
            ORDER BY subject_count DESC, subject ASC
            LIMIT %d",
            $since,
            $until,
            $candidateLimit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return null;
        }

        $groups = [];
        foreach ($rows as $row) {
            $subject = (string) ($row['subject'] ?? '');
            $key = $this->subjectGroups->key($subject);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $this->subjectGroups->label($subject),
                    'count' => 0,
                ];
            }

            $groups[$key]['count'] += max(0, (int) ($row['subject_count'] ?? 0));
        }

        $groups = array_values($groups);
        usort(
            $groups,
            static function (array $a, array $b): int {
                $count = (int) $b['count'] <=> (int) $a['count'];
                if ($count !== 0) {
                    return $count;
                }

                $key = strcmp((string) $a['key'], (string) $b['key']);

                return $key !== 0 ? $key : strcmp((string) $a['label'], (string) $b['label']);
            }
        );

        return array_slice($groups, 0, $limit);
    }

    /** @return array<int,array{period:string,status:string,count:int}>|null */
    private function queryAdvancedTrendRows(string $since, string $until, int $limit): ?array
    {
        global $wpdb;

        $messagesTable = TableNames::messages();
        $sql = $wpdb->prepare(
            "SELECT DATE(created_at) AS period,
                COALESCE(NULLIF(status, ''), 'unknown') AS status,
                COUNT(*) AS status_count
            FROM {$messagesTable}
            WHERE created_at >= %s AND created_at < %s
            GROUP BY DATE(created_at), COALESCE(NULLIF(status, ''), 'unknown')
            ORDER BY period ASC, status ASC
            LIMIT %d",
            $since,
            $until,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return null;
        }

        return array_map(
            static fn (array $row): array => [
                'period' => (string) ($row['period'] ?? ''),
                'status' => sanitize_key((string) ($row['status'] ?? 'unknown')) ?: 'unknown',
                'count' => max(0, (int) ($row['status_count'] ?? 0)),
            ],
            $rows
        );
    }

    /** @return array<int,array{category:string,count:int,last_seen_at:?string}>|null */
    private function queryAdvancedFailureRows(string $since, string $until, int $limit): ?array
    {
        global $wpdb;

        $attemptsTable = TableNames::attempts();
        $sql = $wpdb->prepare(
            "SELECT
                COALESCE(NULLIF(failure_category, ''), 'uncategorized') AS failure_category,
                COUNT(*) AS failure_count,
                MAX(created_at) AS last_seen_at
            FROM {$attemptsTable}
            WHERE result = %s AND created_at >= %s AND created_at < %s
            GROUP BY COALESCE(NULLIF(failure_category, ''), 'uncategorized')
            ORDER BY failure_count DESC, failure_category ASC
            LIMIT %d",
            'fail',
            $since,
            $until,
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return null;
        }

        return array_map(
            static fn (array $row): array => [
                'category' => sanitize_key((string) ($row['failure_category'] ?? 'uncategorized')) ?: 'uncategorized',
                'count' => max(0, (int) ($row['failure_count'] ?? 0)),
                'last_seen_at' => isset($row['last_seen_at']) && (string) $row['last_seen_at'] !== '' ? (string) $row['last_seen_at'] : null,
            ],
            $rows
        );
    }

    /**
     * @return array<int,array{provider_id:int,provider_name:string,adapter_type:string,sent_count:int,failed_count:int,retry_count:int,avg_latency_ms:?int,failover_count:int,switch_out_count:int,total_activity:int}>
     */
    public function getProviderBreakdown(string $since): array
    {
        global $wpdb;

        $attemptsTable = TableNames::attempts();
        $providersTable = TableNames::providers();
        $attemptSql = $wpdb->prepare(
            "SELECT
                COALESCE(a.provider_id, 0) AS provider_id,
                COALESCE(p.name, 'Unknown provider') AS provider_name,
                COALESCE(p.adapter_type, 'unknown') AS adapter_type,
                COALESCE(SUM(CASE WHEN a.result = 'sent' THEN 1 ELSE 0 END), 0) AS sent_count,
                COALESCE(SUM(CASE WHEN a.result = 'fail' THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN a.trigger_type = 'retry' THEN 1 ELSE 0 END), 0) AS retry_count,
                AVG(CASE WHEN a.latency_ms IS NOT NULL THEN a.latency_ms END) AS avg_latency_ms
            FROM {$attemptsTable} a
            LEFT JOIN {$providersTable} p ON p.id = a.provider_id
            WHERE a.created_at >= %s
            GROUP BY COALESCE(a.provider_id, 0), p.name, p.adapter_type",
            $since
        );

        $rows = [];
        $attemptRows = $wpdb->get_results($attemptSql, ARRAY_A);
        foreach (is_array($attemptRows) ? $attemptRows : [] as $row) {
            $providerId = (int) ($row['provider_id'] ?? 0);
            $rows[$providerId] = [
                'provider_id'    => $providerId,
                'provider_name'  => (string) ($row['provider_name'] ?? 'Unknown provider'),
                'adapter_type'   => (string) ($row['adapter_type'] ?? 'unknown'),
                'sent_count'     => (int) ($row['sent_count'] ?? 0),
                'failed_count'   => (int) ($row['failed_count'] ?? 0),
                'retry_count'    => (int) ($row['retry_count'] ?? 0),
                'avg_latency_ms' => isset($row['avg_latency_ms']) ? max(0, (int) round((float) $row['avg_latency_ms'])) : null,
                'failover_count' => 0,
                'switch_out_count' => 0,
                'total_activity' => 0,
            ];
        }

        $eventsTable = TableNames::events();
        $failoverSql = $wpdb->prepare(
            "SELECT
                COALESCE(e.provider_id, 0) AS provider_id,
                COALESCE(p.name, 'Unknown provider') AS provider_name,
                COALESCE(p.adapter_type, 'unknown') AS adapter_type,
                COUNT(e.id) AS failover_count
            FROM {$eventsTable} e
            LEFT JOIN {$providersTable} p ON p.id = e.provider_id
            WHERE e.event_type = %s AND e.created_at >= %s
            GROUP BY COALESCE(e.provider_id, 0), p.name, p.adapter_type",
            'provider_failover',
            $since
        );

        $failoverRows = $wpdb->get_results($failoverSql, ARRAY_A);
        foreach (is_array($failoverRows) ? $failoverRows : [] as $row) {
            $providerId = (int) ($row['provider_id'] ?? 0);
            if (! isset($rows[$providerId])) {
                $rows[$providerId] = [
                    'provider_id'    => $providerId,
                    'provider_name'  => (string) ($row['provider_name'] ?? 'Unknown provider'),
                    'adapter_type'   => (string) ($row['adapter_type'] ?? 'unknown'),
                    'sent_count'     => 0,
                    'failed_count'   => 0,
                    'retry_count'    => 0,
                    'avg_latency_ms' => null,
                    'failover_count' => 0,
                    'switch_out_count' => 0,
                    'total_activity' => 0,
                ];
            }

            $rows[$providerId]['failover_count'] = (int) ($row['failover_count'] ?? 0);
        }

        // The failover event is attributed to the destination provider above.
        // Decode the redacted event context as well so the dashboard can show
        // how often a provider was switched away from, which is the useful
        // reliability signal for an operator.
        $eventSql = $wpdb->prepare(
            "SELECT context_json FROM {$eventsTable} WHERE event_type = %s AND created_at >= %s",
            'provider_failover',
            $since
        );
        $eventRows = $wpdb->get_results($eventSql, ARRAY_A);
        $providerMeta = [];
        $providerRows = $wpdb->get_results("SELECT id, name, adapter_type FROM {$providersTable}", ARRAY_A);
        foreach (is_array($providerRows) ? $providerRows : [] as $provider) {
            $providerMeta[(int) ($provider['id'] ?? 0)] = [
                'provider_name' => (string) ($provider['name'] ?? 'Unknown provider'),
                'adapter_type' => (string) ($provider['adapter_type'] ?? 'unknown'),
            ];
        }
        foreach (is_array($eventRows) ? $eventRows : [] as $event) {
            $context = json_decode((string) ($event['context_json'] ?? ''), true);
            $fromProviderId = is_array($context) ? (int) ($context['from_provider_id'] ?? 0) : 0;
            if ($fromProviderId <= 0) {
                continue;
            }

            if (! isset($rows[$fromProviderId])) {
                $meta = $providerMeta[$fromProviderId] ?? [];
                $rows[$fromProviderId] = [
                    'provider_id' => $fromProviderId,
                    'provider_name' => (string) ($meta['provider_name'] ?? 'Unknown provider'),
                    'adapter_type' => (string) ($meta['adapter_type'] ?? 'unknown'),
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'retry_count' => 0,
                    'avg_latency_ms' => null,
                    'failover_count' => 0,
                    'switch_out_count' => 0,
                    'total_activity' => 0,
                ];
            }

            $rows[$fromProviderId]['switch_out_count']++;
        }

        foreach ($rows as &$row) {
            $row['total_activity'] = (int) $row['sent_count'] + (int) $row['failed_count'] + (int) $row['retry_count'] + (int) $row['failover_count'] + (int) $row['switch_out_count'];
        }
        unset($row);

        usort(
            $rows,
            static function (array $a, array $b): int {
                $activity = ((int) $b['total_activity']) <=> ((int) $a['total_activity']);
                if ($activity !== 0) {
                    return $activity;
                }

                return strcmp((string) $a['provider_name'], (string) $b['provider_name']);
            }
        );

        return array_values($rows);
    }
}
