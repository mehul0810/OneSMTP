<?php

declare(strict_types=1);

namespace OneSMTP\Core;

final class DatabaseSchema
{
    public static function createTables(): void
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $providersTable = TableNames::providers();
        $messagesTable  = TableNames::messages();
        $attemptsTable  = TableNames::attempts();
        $eventsTable    = TableNames::events();
        $quotaLeasesTable = TableNames::quotaLeases();
        $providerEventsTable = TableNames::providerEvents();
        $providerEventReplaysTable = TableNames::providerEventReplays();
        $suppressionsTable = TableNames::suppressions();
        $suppressionDerivationsTable = TableNames::suppressionDerivations();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $providersSql = "CREATE TABLE {$providersTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(64) NOT NULL,
            name VARCHAR(190) NOT NULL,
            adapter_type VARCHAR(64) NOT NULL,
            priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            weight SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            circuit_state VARCHAR(20) NOT NULL DEFAULT 'closed',
            circuit_until DATETIME NULL,
            config_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY is_active (is_active),
            KEY priority (priority)
        ) {$charsetCollate};";

        $messagesSql = "CREATE TABLE {$messagesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_uuid CHAR(36) NOT NULL,
            subject VARCHAR(255) NULL,
            recipients_hash CHAR(64) NOT NULL,
            body_hash CHAR(64) NOT NULL,
            payload_json LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            selected_provider_id BIGINT UNSIGNED NULL,
            current_attempt SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 6,
            next_retry_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY message_uuid (message_uuid),
            KEY status (status),
            KEY selected_provider_id (selected_provider_id),
            KEY next_retry_at (next_retry_at),
            KEY status_retry (status, next_retry_at),
            KEY provider_status (selected_provider_id, status),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        $attemptsSql = "CREATE TABLE {$attemptsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            attempt_no SMALLINT UNSIGNED NOT NULL,
            provider_id BIGINT UNSIGNED NULL,
            trigger_type VARCHAR(20) NOT NULL DEFAULT 'initial',
            result VARCHAR(10) NOT NULL DEFAULT 'fail',
            error_code VARCHAR(64) NULL,
            error_message TEXT NULL,
            failure_category VARCHAR(32) NULL,
            latency_ms INT UNSIGNED NULL,
            provider_message_id VARCHAR(190) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY message_id (message_id),
            KEY provider_id (provider_id),
            KEY result (result),
            KEY failure_category (failure_category),
            UNIQUE KEY message_attempt (message_id, attempt_no),
            KEY provider_message_id (provider_id, provider_message_id),
            KEY provider_result_time (provider_id, result, created_at),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        $eventsSql = "CREATE TABLE {$eventsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(64) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            message_id BIGINT UNSIGNED NULL,
            provider_id BIGINT UNSIGNED NULL,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY message_id (message_id),
            KEY provider_id (provider_id),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        $quotaLeasesSql = "CREATE TABLE {$quotaLeasesTable} (
            lease_key VARCHAR(190) NOT NULL,
            lease_type VARCHAR(20) NOT NULL,
            provider_id BIGINT UNSIGNED NULL,
            owner_token CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (lease_key),
            KEY provider_expiry (lease_type, provider_id, expires_at),
            KEY expires_at (expires_at)
        ) {$charsetCollate};";

        $providerEventsSql = "CREATE TABLE {$providerEventsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(64) NOT NULL,
            provider_id BIGINT UNSIGNED NULL,
            message_id BIGINT UNSIGNED NULL,
            provider_message_id VARCHAR(128) NULL,
            event_type VARCHAR(20) NOT NULL,
            occurred_at DATETIME NOT NULL,
            external_event_hash CHAR(64) NOT NULL,
            event_data_hash CHAR(64) NULL,
            replay_token_hash CHAR(64) NULL,
            recipient_fingerprint CHAR(64) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY external_event_hash (external_event_hash),
            UNIQUE KEY replay_token_hash (replay_token_hash),
            KEY provider_message_id (provider_id, provider_message_id),
            KEY message_id (message_id),
            KEY event_data_hash (event_data_hash),
            KEY event_type (event_type),
            KEY occurred_at (occurred_at),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        $providerEventReplaysSql = "CREATE TABLE {$providerEventReplaysTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(64) NOT NULL,
            replay_token_hash CHAR(64) NOT NULL,
            event_data_hash CHAR(64) NOT NULL,
            external_event_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY replay_token_hash (replay_token_hash),
            KEY external_event_hash (external_event_hash),
            KEY created_at (created_at)
        ) {$charsetCollate};";

        $suppressionsSql = "CREATE TABLE {$suppressionsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipient_fingerprint CHAR(64) NOT NULL,
            recipient_domain VARCHAR(253) NOT NULL,
            reason_code VARCHAR(32) NOT NULL,
            provider VARCHAR(64) NOT NULL,
            provider_id BIGINT UNSIGNED NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            expiry_at DATETIME NOT NULL,
            occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY recipient_fingerprint (recipient_fingerprint),
            KEY expiry_at (expiry_at),
            KEY recipient_domain (recipient_domain),
            KEY reason_code (reason_code),
            KEY updated_at (updated_at)
        ) {$charsetCollate};";

        $suppressionDerivationsSql = "CREATE TABLE {$suppressionDerivationsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_event_hash CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'processing',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY external_event_hash (external_event_hash),
            KEY status_updated_at (status, updated_at),
            KEY updated_at (updated_at)
        ) {$charsetCollate};";

        dbDelta($providersSql);
        dbDelta($messagesSql);
        dbDelta($attemptsSql);
        dbDelta($eventsSql);
        dbDelta($quotaLeasesSql);
        dbDelta($providerEventsSql);
        dbDelta($providerEventReplaysSql);
        dbDelta($suppressionsSql);
        dbDelta($suppressionDerivationsSql);
    }

    /**
     * Return every plugin-owned table that must exist before schema migration
     * can be marked current.
     *
     * @return array<int,string>
     */
    public static function requiredTables(): array
    {
        return [
            TableNames::providers(),
            TableNames::messages(),
            TableNames::attempts(),
            TableNames::events(),
            TableNames::quotaLeases(),
            TableNames::providerEvents(),
            TableNames::providerEventReplays(),
            TableNames::suppressions(),
            TableNames::suppressionDerivations(),
        ];
    }

    public static function verifyRequiredTables(): bool
    {
        global $wpdb;

        foreach (self::requiredTables() as $table) {
            $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table));
            if ( ! is_string($sql) ) {
                return false;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query is prepared immediately above.
            $foundTable = $wpdb->get_var($sql);
            if ( (string) $foundTable !== $table ) {
                return false;
            }
        }

        return true;
    }
}
