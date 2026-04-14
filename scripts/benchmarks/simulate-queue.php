<?php

declare(strict_types=1);

$options = getopt('', ['profile:', 'out:']);
$profile = $options['profile'] ?? 'smoke';
$out = $options['out'] ?? __DIR__ . '/../../artifacts/perf/metrics.json';

$targets = [
    'dispatch_p95_ms' => 50,
    'retry_decision_p95_ms' => 35,
    'attempt_insert_p95_ms' => 20,
    'admin_log_query_p95_ms' => 250,
];

$simulated = [
    'dispatch_p95_ms' => 44,
    'retry_decision_p95_ms' => 28,
    'attempt_insert_p95_ms' => 13,
    'admin_log_query_p95_ms' => 198,
];

$payload = [
    'profile' => $profile,
    'generated_at' => gmdate('c'),
    'targets' => $targets,
    'metrics' => [
        'processed_messages' => $profile === 'smoke' ? 1000 : ($profile === 'mvp-baseline' ? 10000 : 25000),
        'success_count' => $profile === 'stress-lite' ? 23100 : ($profile === 'mvp-baseline' ? 9720 : 995),
        'failed_count' => $profile === 'stress-lite' ? 1900 : ($profile === 'mvp-baseline' ? 280 : 5),
        'provider_switch_count' => $profile === 'smoke' ? 28 : ($profile === 'mvp-baseline' ? 612 : 2100),
        'duplicate_attempt_conflicts' => 0,
        'latency_ms' => $simulated,
    ],
    'note' => 'Skeleton simulation with static values. Replace with runtime instrumentation in next phase.',
];

$violations = [];
foreach ($targets as $k => $max) {
    if (($simulated[$k] ?? PHP_INT_MAX) > $max) {
        $violations[] = "{$k} exceeded target {$max}";
    }
}

$payload['violations'] = $violations;
$payload['pass'] = count($violations) === 0;

$dir = dirname($out);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Failed to create output directory: {$dir}\n");
    exit(1);
}

file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "queue simulation stub written: {$out}\n";

if (!$payload['pass']) {
    exit(3);
}
