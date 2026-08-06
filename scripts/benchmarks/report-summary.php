<?php

declare(strict_types=1);

$options = getopt('', ['in:', 'out:']);
$in = $options['in'] ?? __DIR__ . '/../../artifacts/perf/metrics.json';
$out = $options['out'] ?? __DIR__ . '/../../artifacts/perf/summary.md';

if (!is_readable($in)) {
    fwrite(STDERR, "Metrics input not readable: {$in}\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($in), true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON metrics payload\n");
    exit(1);
}

$lat = $data['metrics']['latency_ms'] ?? [];
$logTables = $data['metrics']['log_tables'] ?? [];
$logLat = is_array($logTables) ? ($logTables['latency_ms'] ?? []) : [];
$schemaChecks = is_array($logTables) ? ($logTables['schema_index_checks'] ?? []) : [];
$lines = [
    '# Aculect Mail Performance Summary',
    '',
    '- Profile: ' . ($data['profile'] ?? 'unknown'),
    '- Generated: ' . ($data['generated_at'] ?? gmdate('c')),
    '- Pass: ' . (($data['pass'] ?? false) ? 'yes' : 'no'),
    '- Processed: ' . ($data['metrics']['processed_messages'] ?? 0),
    '- Success: ' . ($data['metrics']['success_count'] ?? 0),
    '- Failed: ' . ($data['metrics']['failed_count'] ?? 0),
    '- Provider switches: ' . ($data['metrics']['provider_switch_count'] ?? 0),
    '- Duplicate conflicts: ' . ($data['metrics']['duplicate_attempt_conflicts'] ?? 0),
    '',
    '## Latency (p95)',
    '',
    '- Dispatch decision: ' . ($lat['dispatch_p95_ms'] ?? 'n/a') . 'ms',
    '- Retry decision: ' . ($lat['retry_decision_p95_ms'] ?? 'n/a') . 'ms',
    '- Attempt insert: ' . ($lat['attempt_insert_p95_ms'] ?? 'n/a') . 'ms',
    '- Admin log query: ' . ($lat['admin_log_query_p95_ms'] ?? 'n/a') . 'ms',
];

if (is_array($logTables) && $logTables !== []) {
    $lines[] = '';
    $lines[] = '## Large Log Tables';
    $lines[] = '';
    $lines[] = '- Synthetic messages: ' . ($logTables['synthetic_messages'] ?? 0);
    $lines[] = '- Synthetic attempts: ' . ($logTables['synthetic_attempts'] ?? 0);
    $lines[] = '- Fixture seed: ' . ($logTables['fixture_seed_ms'] ?? 'n/a') . 'ms';
    $lines[] = '- List path p95: ' . (is_array($logLat) ? ($logLat['admin_log_list_p95_ms'] ?? 'n/a') : 'n/a') . 'ms';
    $lines[] = '- Filter path p95: ' . (is_array($logLat) ? ($logLat['admin_log_filter_p95_ms'] ?? 'n/a') : 'n/a') . 'ms';
    $lines[] = '- Export path p95: ' . (is_array($logLat) ? ($logLat['admin_log_export_p95_ms'] ?? 'n/a') : 'n/a') . 'ms';
    $lines[] = '- Detail path p95: ' . (is_array($logLat) ? ($logLat['admin_log_detail_p95_ms'] ?? 'n/a') : 'n/a') . 'ms';
    $lines[] = '- Schema index checks: ' . (empty($schemaChecks['missing']) ? 'pass' : 'fail');
}

if (!empty($data['violations'])) {
    $lines[] = '';
    $lines[] = '## Violations';
    $lines[] = '';
    foreach ($data['violations'] as $violation) {
        $lines[] = '- ' . $violation;
    }
}

$dir = dirname($out);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Failed to create output directory: {$dir}\n");
    exit(1);
}

file_put_contents($out, implode(PHP_EOL, $lines) . PHP_EOL);

echo "summary written: {$out}\n";
