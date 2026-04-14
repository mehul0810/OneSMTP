<?php

declare(strict_types=1);

namespace OneSMTP\Core;

final class DatabaseSchema
{
    public static function createTables(): void
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $prefix         = $wpdb->prefix . 'onesmtp_';

        $providersTable = $prefix . 'providers';
        $messagesTable  = $prefix . 'messages';
        $attemptsTable  = $prefix . 'attempts';
        $eventsTable    = $prefix . 'events';

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
            KEY next_retry_at (next_retry_at)
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
            latency_ms INT UNSIGNED NULL,
            provider_message_id VARCHAR(190) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY message_id (message_id),
            KEY provider_id (provider_id),
            KEY result (result)
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
            KEY provider_id (provider_id)
        ) {$charsetCollate};";

        dbDelta($providersSql);
        dbDelta($messagesSql);
        dbDelta($attemptsSql);
        dbDelta($eventsSql);
    }
}
