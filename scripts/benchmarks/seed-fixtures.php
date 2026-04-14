<?php

declare(strict_types=1);

$options = getopt('', ['profile:', 'out:']);
$profile = $options['profile'] ?? 'smoke';
$out = $options['out'] ?? __DIR__ . '/../../artifacts/perf/seed.json';

$profiles = [
    'smoke' => ['messages' => 1000, 'providers' => 5, 'transient_failure_rate' => 0.05, 'hard_failure_rate' => 0.00],
    'mvp-baseline' => ['messages' => 10000, 'providers' => 5, 'transient_failure_rate' => 0.15, 'hard_failure_rate' => 0.02],
    'stress-lite' => ['messages' => 25000, 'providers' => 5, 'transient_failure_rate' => 0.25, 'hard_failure_rate' => 0.05],
];

if (!isset($profiles[$profile])) {
    fwrite(STDERR, "Unknown profile: {$profile}\n");
    exit(2);
}

$payload = [
    'profile' => $profile,
    'seeded_at' => gmdate('c'),
    'config' => $profiles[$profile],
    'note' => 'Skeleton fixture output; real DB seeding is added in implementation phase.',
];

$dir = dirname($out);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Failed to create output directory: {$dir}\n");
    exit(1);
}

file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "fixture seed stub written: {$out}\n";
