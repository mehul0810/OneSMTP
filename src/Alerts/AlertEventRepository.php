<?php

declare(strict_types=1);

namespace OneSMTP\Alerts;

use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Core\TableNames;
use OneSMTP\Security\Redactor;

final class AlertEventRepository
{
    private const ALERT_EVENT_TYPES = [
        'terminal_failure',
    ];

    public function __construct(
        private ?AdminAuditLogger $auditLogger = null,
        private ?Redactor $redactor = null
    ) {
        $this->auditLogger = $auditLogger ?? new AdminAuditLogger();
        $this->redactor = $redactor ?? new Redactor();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 20): array
    {
        global $wpdb;

        $limit = max(1, min(50, $limit));
        $eventsTable = TableNames::events();
        $placeholders = implode(', ', array_fill(0, count(self::ALERT_EVENT_TYPES), '%s'));
        $args = array_merge(self::ALERT_EVENT_TYPES, [$limit]);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names come from TableNames and values are prepared below.
        $sql = $wpdb->prepare(
            'SELECT id, event_type, actor_id, message_id, provider_id, context_json, created_at
            FROM ' . $eventsTable . '
            WHERE event_type IN (' . $placeholders . ')
            ORDER BY id DESC
            LIMIT %d',
            ...$args
        );

        $rows = $wpdb->get_results(
            $sql,
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return $this->withAcknowledgementStatus(is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findAlert(int $eventId): ?array
    {
        global $wpdb;

        if ($eventId <= 0) {
            return null;
        }

        $eventsTable = TableNames::events();
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name comes from TableNames and values are prepared below.
        $sql = $wpdb->prepare(
            'SELECT id, event_type, actor_id, message_id, provider_id, context_json, created_at
            FROM ' . $eventsTable . '
            WHERE id = %d
            AND event_type = %s
            LIMIT 1',
            $eventId,
            'terminal_failure'
        );

        $row = $wpdb->get_row(
            $sql,
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return is_array($row) ? $this->normalizeEvent($row) : null;
    }

    public function acknowledge(int $eventId): int
    {
        $event = $this->findAlert($eventId);
        if ($event === null) {
            return 0;
        }

        return $this->auditLogger->logAlertAcknowledgement(
            (string) $event['event_type'],
            [
                'alert_event_id' => (int) $event['id'],
                'alert_event_type' => (string) $event['event_type'],
                'alert_status' => 'acknowledged',
                'summary' => sprintf('Acknowledged alert event #%d.', (int) $event['id']),
                'alert_context' => $event['context'],
            ]
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function withAcknowledgementStatus(array $rows): array
    {
        $events = array_map(fn (array $row): array => $this->normalizeEvent($row), $rows);
        $eventIds = array_values(array_filter(array_map(static fn (array $event): int => (int) $event['id'], $events)));

        if ($eventIds === []) {
            return [];
        }

        $acknowledgements = $this->acknowledgementsFor($eventIds);

        foreach ($events as &$event) {
            $acknowledgement = $acknowledgements[ (int) $event['id'] ] ?? null;
            $event['status'] = $acknowledgement !== null ? 'acknowledged' : 'open';
            $event['acknowledged_by'] = is_array($acknowledgement) ? (int) ($acknowledgement['actor_id'] ?? 0) : 0;
            $event['acknowledged_at'] = is_array($acknowledgement) ? (string) ($acknowledgement['created_at'] ?? '') : '';
        }
        unset($event);

        return $events;
    }

    /**
     * @param array<int,int> $eventIds
     * @return array<int,array<string,mixed>>
     */
    private function acknowledgementsFor(array $eventIds): array
    {
        global $wpdb;

        $eventIds = array_values(array_unique(array_filter(array_map('absint', $eventIds))));
        if ($eventIds === []) {
            return [];
        }

        $eventsTable = TableNames::events();
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name comes from TableNames and values are prepared below.
        $sql = $wpdb->prepare(
            'SELECT id, actor_id, context_json, created_at
            FROM ' . $eventsTable . '
            WHERE event_type = %s
            ORDER BY id DESC
            LIMIT 100',
            'audit_alert_acknowledged'
        );

        $rows = $wpdb->get_results(
            $sql,
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        $acknowledgements = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $context = $this->decodeContext( (string) ($row['context_json'] ?? ''));
            $alertEventId = absint($context['alert_event_id'] ?? 0);
            if ( ! in_array($alertEventId, $eventIds, true) || isset($acknowledgements[ $alertEventId ])) {
                continue;
            }

            $acknowledgements[ $alertEventId ] = [
                'actor_id' => isset($row['actor_id']) ? absint($row['actor_id']) : 0,
                'created_at' => sanitize_text_field( (string) ($row['created_at'] ?? '')),
            ];
        }

        return $acknowledgements;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeEvent(array $row): array
    {
        $context = $this->redactor->redactArray($this->decodeContext( (string) ($row['context_json'] ?? '')));

        return [
            'id' => absint($row['id'] ?? 0),
            'event_type' => sanitize_key( (string) ($row['event_type'] ?? '')),
            'actor_id' => isset($row['actor_id']) ? absint($row['actor_id']) : 0,
            'message_id' => isset($row['message_id']) ? absint($row['message_id']) : 0,
            'provider_id' => isset($row['provider_id']) ? absint($row['provider_id']) : 0,
            'context' => $context,
            'summary' => $this->summaryFor($context),
            'created_at' => sanitize_text_field( (string) ($row['created_at'] ?? '')),
            'status' => 'open',
            'acknowledged_by' => 0,
            'acknowledged_at' => '',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeContext(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function summaryFor(array $context): string
    {
        if (isset($context['summary']) && is_string($context['summary']) && $context['summary'] !== '') {
            return $this->redactor->redactText(sanitize_text_field($context['summary']), 160);
        }

        $reason = isset($context['reason']) ? sanitize_key( (string) $context['reason']) : '';
        $category = isset($context['failure_category']) ? sanitize_key( (string) $context['failure_category']) : '';
        $parts = array_filter([$reason, $category]);

        return $parts === []
            ? __('Alert event recorded.', 'onesmtp')
            /* translators: %s: Privacy-safe alert reason/category summary. */
            : sprintf(__('Alert event: %s.', 'onesmtp'), implode(' / ', $parts));
    }
}
