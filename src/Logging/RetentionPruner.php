<?php

declare(strict_types=1);

namespace OneSMTP\Logging;

use OneSMTP\Core\RetentionPolicy;
use OneSMTP\Core\TableNames;

final class RetentionPruner
{
    private const ACTION_HOOK = 'onesmtp_prune_logs';
    private const GROUP       = 'onesmtp';
    private const DEFAULT_BATCH_SIZE = 500;

    public function registerHooks(): void
    {
        add_action(self::ACTION_HOOK, [$this, 'prune']);
        $this->schedule();
    }

    public function schedule(): void
    {
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            if (! as_next_scheduled_action(self::ACTION_HOOK, [], self::GROUP)) {
                as_schedule_recurring_action(time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::ACTION_HOOK, [], self::GROUP);
            }
            return;
        }

        if (! wp_next_scheduled(self::ACTION_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::ACTION_HOOK);
        }
    }

    public function prune(): void
    {
        $days       = RetentionPolicy::getLogRetentionDays();
        $cutoff     = gmdate('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * $days));
        $batchSize  = (int) apply_filters('onesmtp_retention_batch_size', self::DEFAULT_BATCH_SIZE);

        if ($batchSize < 100) {
            $batchSize = 100;
        }

        $this->deleteInBatches(
            TableNames::attempts(),
            'created_at < %s',
            [$cutoff],
            $batchSize
        );
        $this->deleteInBatches(
            TableNames::events(),
            'created_at < %s',
            [$cutoff],
            $batchSize
        );
        $this->deleteInBatches(
            TableNames::messages(),
            "created_at < %s AND status IN ('sent','failed')",
            [$cutoff],
            $batchSize
        );
    }

    private function deleteInBatches(string $tableName, string $whereSql, array $params, int $batchSize): void
    {
        global $wpdb;

        do {
            $query = "DELETE FROM {$tableName} WHERE {$whereSql} LIMIT %d";
            $args  = array_merge($params, [$batchSize]);
            $sql   = $wpdb->prepare($query, ...$args);

            if (! is_string($sql)) {
                break;
            }

            $deletedRows = $wpdb->query($sql);
            if (! is_numeric($deletedRows) || (int) $deletedRows <= 0) {
                break;
            }
        } while ((int) $deletedRows === $batchSize);
    }
}
