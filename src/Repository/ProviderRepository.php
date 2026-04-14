<?php

declare(strict_types=1);

namespace OneSMTP\Repository;

use OneSMTP\Core\TableNames;

final class ProviderRepository
{
    public function getActiveProviders(): array
    {
        global $wpdb;

        $sql = 'SELECT * FROM ' . TableNames::providers() . " WHERE is_active = 1 ORDER BY priority ASC, id ASC";
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function find(int $providerId): ?array
    {
        global $wpdb;

        $sql = $wpdb->prepare('SELECT * FROM ' . TableNames::providers() . ' WHERE id = %d', $providerId);
        $row = $wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }
}
