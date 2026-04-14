<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Core\TableNames;

final class EventRepository
{
    public function add(string $eventType, array $context = [], ?int $messageId = null, ?int $providerId = null): int
    {
        global $wpdb;

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

        return (int) $wpdb->insert_id;
    }
}
