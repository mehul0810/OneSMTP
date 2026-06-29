<?php

declare(strict_types=1);

$options = getopt('', ['profile:', 'in:', 'out:']);
$profile = (string) ($options['profile'] ?? 'smoke');
$in = (string) ($options['in'] ?? __DIR__ . '/../../artifacts/perf/metrics.json');
$out = (string) ($options['out'] ?? $in);

$profiles = [
    'smoke' => ['messages' => 20000, 'attempts' => 100000, 'providers' => 5, 'iterations' => 20],
    'mvp-baseline' => ['messages' => 50000, 'attempts' => 250000, 'providers' => 5, 'iterations' => 24],
    'stress-lite' => ['messages' => 100000, 'attempts' => 500000, 'providers' => 5, 'iterations' => 30],
];

if (! isset($profiles[$profile])) {
    fwrite(STDERR, "Unknown profile: {$profile}\n");
    exit(2);
}

$payload = [];
if (is_readable($in)) {
    $decoded = json_decode((string) file_get_contents($in), true);
    $payload = is_array($decoded) ? $decoded : [];
}

$config = $profiles[$profile];
$started = hrtime(true);
$dataset = build_log_dataset((int) $config['messages'], (int) $config['attempts'], (int) $config['providers']);
$seedMs = elapsed_ms($started);

$filters = [
    'status' => 'failed',
    'provider_id' => 3,
    'date_from' => '2026-06-01',
    'date_to' => '2026-06-30',
    'recipient_hash' => '',
    'search' => '',
];
$exportFilters = [
    'status' => 'sent',
    'provider_id' => 0,
    'date_from' => '2026-06-01',
    'date_to' => '2026-06-30',
    'recipient_hash' => '',
    'search' => '',
];

$iterations = (int) $config['iterations'];
$listRuns = [];
$filterRuns = [];
$exportRuns = [];
$detailRuns = [];
$lastListCount = 0;
$lastFilterCount = 0;
$lastExportCount = 0;
$lastDetailAttempts = 0;

for ($i = 0; $i < $iterations; $i++) {
    $page = ($i % 5) + 1;
    $detailId = ((int) $config['messages']) - ($i * 37 % max(1, (int) $config['messages']));

    $started = hrtime(true);
    $rows = list_log_rows($dataset, [], $page, 50);
    $listRuns[] = elapsed_ms($started);
    $lastListCount = count($rows);

    $started = hrtime(true);
    $rows = list_log_rows($dataset, $filters, $page, 50);
    $filterRuns[] = elapsed_ms($started);
    $lastFilterCount = count($rows);

    $started = hrtime(true);
    $rows = export_log_rows($dataset, $exportFilters, 1000);
    $exportRuns[] = elapsed_ms($started);
    $lastExportCount = count($rows);

    $started = hrtime(true);
    $detail = detail_log_row($dataset, $detailId);
    $detailRuns[] = elapsed_ms($started);
    $lastDetailAttempts = count($detail['attempts']);
}

$schemaChecks = schema_index_checks();
$targets = [
    'admin_log_list_p95_ms' => 250,
    'admin_log_filter_p95_ms' => 300,
    'admin_log_export_p95_ms' => 750,
    'admin_log_detail_p95_ms' => 75,
];
$latency = [
    'admin_log_list_p95_ms' => p95($listRuns),
    'admin_log_filter_p95_ms' => p95($filterRuns),
    'admin_log_export_p95_ms' => p95($exportRuns),
    'admin_log_detail_p95_ms' => p95($detailRuns),
];

$violations = is_array($payload['violations'] ?? null) ? $payload['violations'] : [];
foreach ($targets as $key => $max) {
    if (($latency[$key] ?? INF) > $max) {
        $violations[] = "{$key} exceeded target {$max}";
    }
}

foreach ($schemaChecks['missing'] as $missingIndex) {
    $violations[] = "missing expected log-table index {$missingIndex}";
}

$payload['profile'] = $payload['profile'] ?? $profile;
$payload['generated_at'] = $payload['generated_at'] ?? gmdate('c');
$payload['targets'] = array_merge(is_array($payload['targets'] ?? null) ? $payload['targets'] : [], $targets);
$payload['metrics'] = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
$payload['metrics']['log_tables'] = [
    'synthetic_messages' => (int) $config['messages'],
    'synthetic_attempts' => (int) $config['attempts'],
    'synthetic_providers' => (int) $config['providers'],
    'fixture_seed_ms' => $seedMs,
    'list_page_size' => 50,
    'filter_page_size' => 50,
    'export_limit' => 1000,
    'detail_attempt_limit' => 6,
    'last_list_count' => $lastListCount,
    'last_filter_count' => $lastFilterCount,
    'last_export_count' => $lastExportCount,
    'last_detail_attempts' => $lastDetailAttempts,
    'latency_ms' => $latency,
    'schema_index_checks' => $schemaChecks,
    'paths' => [
        'list' => 'MessageRepository::listFilteredWithAttemptCounts with empty filters',
        'filter' => 'MessageRepository::listFilteredWithAttemptCounts with status/provider/date filters',
        'export' => 'LogAdmin CSV export paging through MessageRepository::listFilteredWithAttemptCounts',
        'detail' => 'MessageRepository::find plus AttemptRepository::listByMessageId',
    ],
];
$payload['violations'] = array_values(array_unique($violations));
$payload['pass'] = count($payload['violations']) === 0;
$payload['note'] = 'Synthetic benchmark metrics only; data contains deterministic fake hashes, UUIDs, statuses, and provider ids with no recipients, bodies, headers, secrets, or production logs.';

$dir = dirname($out);
if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Failed to create output directory: {$dir}\n");
    exit(1);
}

file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "log table simulation written: {$out}\n";

if (! $payload['pass']) {
    exit(3);
}

/**
 * @return array{
 *     by_id:array<int,array<string,mixed>>,
 *     attempts_by_message:array<int,int>,
 *     recent_ids:array<int,int>,
 *     status_index:array<string,array<int,int>>,
 *     provider_index:array<int,array<int,int>>,
 *     provider_status_index:array<string,array<int,int>>,
 *     recipient_index:array<string,array<int,int>>
 * }
 */
function build_log_dataset(int $messageCount, int $attemptCount, int $providerCount): array
{
    $byId = [];
    $attemptsByMessage = [];
    $recentIds = [];
    $statusIndex = [];
    $providerIndex = [];
    $providerStatusIndex = [];
    $recipientIndex = [];
    $statuses = ['sent', 'failed', 'retry_scheduled', 'retrying', 'queued'];
    $baseTime = strtotime('2026-06-30 23:59:00') ?: time();
    $remainingAttempts = $attemptCount;

    for ($id = $messageCount; $id >= 1; $id--) {
        $status = $statuses[$id % count($statuses)];
        $providerId = ($id % $providerCount) + 1;
        $createdAt = gmdate('Y-m-d H:i:s', $baseTime - (($messageCount - $id) * 37));
        $recipientHash = hash('sha256', 'synthetic-recipient-bucket-' . ($id % 1024));
        $attempts = max(1, min(6, 1 + ($id % 6)));

        if ($id === 1) {
            $attempts = max(1, $remainingAttempts);
        } else {
            $maxForRow = max(1, $remainingAttempts - ($id - 1));
            $attempts = min($attempts, $maxForRow);
        }
        $remainingAttempts -= $attempts;

        $row = [
            'id' => $id,
            'message_uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'recipients_hash' => $recipientHash,
            'status' => $status,
            'selected_provider_id' => $providerId,
            'current_attempt' => $attempts,
            'max_attempts' => 6,
            'next_retry_at' => $status === 'retry_scheduled' ? gmdate('Y-m-d H:i:s', $baseTime + (($id % 60) * 60)) : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'attempt_count' => $attempts,
        ];

        $byId[$id] = $row;
        $attemptsByMessage[$id] = $attempts;
        $recentIds[] = $id;
        $statusIndex[$status][] = $id;
        $providerIndex[$providerId][] = $id;
        $providerStatusIndex[$providerId . ':' . $status][] = $id;
        $recipientIndex[$recipientHash][] = $id;
    }

    return [
        'by_id' => $byId,
        'attempts_by_message' => $attemptsByMessage,
        'recent_ids' => $recentIds,
        'status_index' => $statusIndex,
        'provider_index' => $providerIndex,
        'provider_status_index' => $providerStatusIndex,
        'recipient_index' => $recipientIndex,
    ];
}

/**
 * @param array<string,mixed> $dataset
 * @param array<string,mixed> $filters
 * @return array<int,array<string,mixed>>
 */
function list_log_rows(array $dataset, array $filters, int $page, int $perPage): array
{
    $candidateIds = candidate_ids($dataset, $filters);
    $offset = max(0, ($page - 1) * $perPage);
    $limit = max(1, $perPage);
    $matched = [];
    $seen = 0;

    foreach ($candidateIds as $id) {
        $row = $dataset['by_id'][$id] ?? null;
        if (! is_array($row) || ! row_matches_filters($row, $filters)) {
            continue;
        }

        if ($seen++ < $offset) {
            continue;
        }

        $matched[] = $row;
        if (count($matched) >= $limit) {
            break;
        }
    }

    return $matched;
}

/**
 * @param array<string,mixed> $dataset
 * @param array<string,mixed> $filters
 * @return array<int,int>
 */
function candidate_ids(array $dataset, array $filters): array
{
    $status = (string) ($filters['status'] ?? '');
    $providerId = (int) ($filters['provider_id'] ?? 0);
    $recipientHash = (string) ($filters['recipient_hash'] ?? '');
    $search = trim((string) ($filters['search'] ?? ''));

    if ($recipientHash !== '') {
        return $dataset['recipient_index'][$recipientHash] ?? [];
    }

    if ($search !== '' && ctype_digit($search)) {
        return isset($dataset['by_id'][(int) $search]) ? [(int) $search] : [];
    }

    if ($providerId > 0 && $status !== '') {
        return $dataset['provider_status_index'][$providerId . ':' . $status] ?? [];
    }

    if ($status !== '') {
        return $dataset['status_index'][$status] ?? [];
    }

    if ($providerId > 0) {
        return $dataset['provider_index'][$providerId] ?? [];
    }

    return $dataset['recent_ids'];
}

/**
 * @param array<string,mixed> $row
 * @param array<string,mixed> $filters
 */
function row_matches_filters(array $row, array $filters): bool
{
    $status = (string) ($filters['status'] ?? '');
    $providerId = (int) ($filters['provider_id'] ?? 0);
    $recipientHash = (string) ($filters['recipient_hash'] ?? '');
    $dateFrom = (string) ($filters['date_from'] ?? '');
    $dateTo = (string) ($filters['date_to'] ?? '');
    $search = trim((string) ($filters['search'] ?? ''));

    if ($status !== '' && (string) $row['status'] !== $status) {
        return false;
    }

    if ($providerId > 0 && (int) $row['selected_provider_id'] !== $providerId) {
        return false;
    }

    if ($recipientHash !== '' && (string) $row['recipients_hash'] !== $recipientHash) {
        return false;
    }

    if ($dateFrom !== '' && (string) $row['created_at'] < $dateFrom . ' 00:00:00') {
        return false;
    }

    if ($dateTo !== '' && (string) $row['created_at'] > $dateTo . ' 23:59:59') {
        return false;
    }

    if ($search !== '' && ! ctype_digit($search) && ! str_contains((string) $row['message_uuid'], $search)) {
        return false;
    }

    return true;
}

/**
 * @param array<string,mixed> $dataset
 * @param array<string,mixed> $filters
 * @return array<int,array<string,mixed>>
 */
function export_log_rows(array $dataset, array $filters, int $limit): array
{
    $exported = [];
    $page = 1;

    while (count($exported) < $limit) {
        $rows = list_log_rows($dataset, $filters, $page, min(100, $limit - count($exported)));
        if ($rows === []) {
            break;
        }

        array_push($exported, ...$rows);
        $page++;
    }

    return $exported;
}

/**
 * @param array<string,mixed> $dataset
 * @return array{message:array<string,mixed>,attempts:array<int,array<string,mixed>>}
 */
function detail_log_row(array $dataset, int $messageId): array
{
    $message = $dataset['by_id'][$messageId] ?? [];
    $attemptCount = (int) ($dataset['attempts_by_message'][$messageId] ?? 0);
    $attempts = [];

    for ($attemptNo = 1; $attemptNo <= $attemptCount; $attemptNo++) {
        $attempts[] = [
            'message_id' => $messageId,
            'attempt_no' => $attemptNo,
            'provider_id' => (($messageId + $attemptNo) % 5) + 1,
            'trigger_type' => $attemptNo === 1 ? 'initial' : 'retry',
            'result' => $attemptNo === $attemptCount ? ($message['status'] === 'sent' ? 'sent' : 'fail') : 'fail',
            'latency_ms' => 20 + (($messageId + $attemptNo) % 80),
            'created_at' => $message['created_at'] ?? '',
        ];
    }

    return ['message' => is_array($message) ? $message : [], 'attempts' => $attempts];
}

/**
 * @return array{checked:array<int,string>,missing:array<int,string>}
 */
function schema_index_checks(): array
{
    $schemaFile = __DIR__ . '/../../src/Core/DatabaseSchema.php';
    $schema = is_readable($schemaFile) ? (string) file_get_contents($schemaFile) : '';
    $expected = [
        'messages.PRIMARY KEY id' => 'PRIMARY KEY  (id)',
        'messages.UNIQUE KEY message_uuid' => 'UNIQUE KEY message_uuid',
        'messages.KEY status' => 'KEY status (status)',
        'messages.KEY selected_provider_id' => 'KEY selected_provider_id (selected_provider_id)',
        'messages.KEY next_retry_at' => 'KEY next_retry_at (next_retry_at)',
        'messages.KEY status_retry' => 'KEY status_retry (status, next_retry_at)',
        'messages.KEY provider_status' => 'KEY provider_status (selected_provider_id, status)',
        'attempts.KEY message_id' => 'KEY message_id (message_id)',
        'attempts.UNIQUE KEY message_attempt' => 'UNIQUE KEY message_attempt (message_id, attempt_no)',
        'attempts.KEY provider_result_time' => 'KEY provider_result_time (provider_id, result, created_at)',
    ];
    $missing = [];

    foreach ($expected as $label => $needle) {
        if (! str_contains($schema, $needle)) {
            $missing[] = $label;
        }
    }

    return ['checked' => array_keys($expected), 'missing' => $missing];
}

/**
 * @param array<int,float> $values
 */
function p95(array $values): float
{
    if ($values === []) {
        return 0.0;
    }

    sort($values);
    $index = (int) ceil(count($values) * 0.95) - 1;

    return round($values[max(0, min($index, count($values) - 1))], 3);
}

function elapsed_ms(int $start): float
{
    return round((hrtime(true) - $start) / 1000000, 3);
}
