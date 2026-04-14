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
    public array $messageRowsById = [];

    /** @var array<string,array<string,mixed>> */
    public array $messageRowsByUuid = [];

    /** @var array<int,array<int,array<string,mixed>>> */
    public array $attemptHistoryByMessage = [];

    /** @var array<int,array<string,mixed>> */
    public array $activeProviders = [];

    /** @var array{query:string,args:array<int,mixed>}|null */
    private ?array $lastPrepared = null;

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

    public function prepare(string $query, mixed ...$args): string
    {
        $this->lastPrepared = [
            'query' => $query,
            'args' => $args,
        ];

        return $query;
    }

    public function get_row(string $sql, mixed $output = null): ?array
    {
        $prepared = $this->lastPrepared;
        if (! is_array($prepared)) {
            return null;
        }

        $query = $prepared['query'];
        $args  = $prepared['args'];

        if (str_contains($query, $this->prefix . 'onesmtp_messages') && str_contains($query, 'message_uuid = %s')) {
            $uuid = isset($args[0]) ? (string) $args[0] : '';

            return $this->messageRowsByUuid[$uuid] ?? null;
        }

        if (str_contains($query, $this->prefix . 'onesmtp_messages') && str_contains($query, 'WHERE id = %d')) {
            $messageId = isset($args[0]) ? (int) $args[0] : 0;

            return $this->messageRowsById[$messageId] ?? null;
        }

        if (str_contains($query, $this->prefix . 'onesmtp_attempts') && str_contains($query, 'ORDER BY id DESC LIMIT 1')) {
            $messageId = isset($args[0]) ? (int) $args[0] : 0;
            $history   = $this->attemptHistoryByMessage[$messageId] ?? [];

            return $history[0] ?? null;
        }

        return null;
    }

    public function get_results(string $sql, mixed $output = null): array
    {
        if (str_contains($sql, $this->prefix . 'onesmtp_providers')) {
            return $this->activeProviders;
        }

        $prepared = $this->lastPrepared;
        if (! is_array($prepared)) {
            return [];
        }

        if (
            str_contains($prepared['query'], $this->prefix . 'onesmtp_attempts')
            && str_contains($prepared['query'], 'ORDER BY id DESC LIMIT 6')
        ) {
            $messageId = isset($prepared['args'][0]) ? (int) $prepared['args'][0] : 0;

            return $this->attemptHistoryByMessage[$messageId] ?? [];
        }

        return [];
    }

    public function get_var(string $sql): int
    {
        $prepared = $this->lastPrepared;
        if (! is_array($prepared)) {
            return 0;
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
}
