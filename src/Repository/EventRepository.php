<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Alerts\FailureAlertDispatcher;
use OneSMTP\Core\TableNames;

final class EventRepository
{
    public function __construct(private ?FailureAlertDispatcher $failureAlerts = null)
    {
        $this->failureAlerts = $failureAlerts ?? new FailureAlertDispatcher();
    }

    public function add(string $eventType, array $context = [], ?int $messageId = null, ?int $providerId = null): int
    {
        global $wpdb;

        if ($eventType === 'terminal_failure') {
            $context = $this->sanitizeTerminalFailureContext($context);
        }

        $inserted = $wpdb->insert(
            TableNames::events(),
            [
                'event_type'   => $eventType,
                'actor_id'     => get_current_user_id() ?: null,
                'message_id'   => $messageId,
                'provider_id'  => $providerId,
                'context_json' => wp_json_encode($context),
                'created_at'   => current_time('mysql', true),
            ],
            ['%s', '%d', '%d', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            return 0;
        }

        $eventId = (int) $wpdb->insert_id;

        if ($eventType === 'terminal_failure') {
            $this->failureAlerts->handleTerminalFailure($context, $messageId, $providerId, $eventId);
        }

        return $eventId;
    }

    /**
     * Terminal alert context is an explicit allowlist. Failure events may be
     * emitted from provider and queue boundaries that have access to the raw
     * mail payload; none of that payload belongs in the events table.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function sanitizeTerminalFailureContext(array $context): array
    {
        $safe = [];
        if (array_key_exists('attempt', $context)) {
            $safe['attempt'] = max(0, (int) $context['attempt']);
        }

        foreach (['reason', 'failure_category', 'message_type', 'priority'] as $key) {
            if (isset($context[$key])) {
                $safe[$key] = sanitize_key((string) $context[$key]);
            }
        }

        if (array_key_exists('high_priority', $context)) {
            $safe['high_priority'] = ! empty($context['high_priority']);
        }

        if (array_key_exists('consecutive_failures', $context)) {
            $safe['consecutive_failures'] = max(0, (int) $context['consecutive_failures']);
        }

        return $safe;
    }
}
