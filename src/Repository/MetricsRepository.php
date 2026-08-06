<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Core\TableNames;

final class MetricsRepository
{
    /*
     * Repository queries use only plugin-owned identifiers from TableNames.
     * Every runtime value is passed through wpdb::prepare() before execution.
     * Plugin Check cannot follow that invariant across the TableNames helper
     * and intermediate prepared SQL variables.
     */
    // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

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
     * @return array<int,array{provider_id:int,provider_name:string,adapter_type:string,sent_count:int,failed_count:int,retry_count:int,failover_count:int,switch_out_count:int,total_activity:int}>
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
                COALESCE(SUM(CASE WHEN a.trigger_type = 'retry' THEN 1 ELSE 0 END), 0) AS retry_count
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
