<?php

declare(strict_types=1);

namespace OneSMTP\Cli;

use OneSMTP\Core\Capabilities;
use OneSMTP\Diagnostics\DiagnosticReportGenerator;
use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;

final class DiagnosticsCommand
{
    private const FORMAT_JSON = 'json';
    private const FORMAT_TABLE = 'table';

    private DiagnosticReportGenerator $reportGenerator;

    public function __construct(?DiagnosticReportGenerator $reportGenerator = null)
    {
        $queueDiagnostics = new QueueDiagnostics(new ActionSchedulerHealth(), new MessageRepository());

        $this->reportGenerator = $reportGenerator ?? new DiagnosticReportGenerator(
            new ProviderRepository(),
            $queueDiagnostics,
            new AttemptRepository()
        );
    }

    public static function register(?self $command = null): void
    {
        if (! self::isWpCliAvailable()) {
            return;
        }

        $command = $command ?? new self();

        \WP_CLI::add_command('onesmtp diagnostics', [$command, 'report']);
    }

    /**
     * Outputs a privacy-safe OneSMTP diagnostic report.
     *
     * ## OPTIONS
     *
     * [--section=<section>]
     * : Report section. Accepted values: report, providers, queue, failures. Default: report.
     *
     * [--format=<format>]
     * : Output format. Accepted values: json, table. Default: json.
     *
     * ## EXAMPLES
     *
     *     wp onesmtp diagnostics --user=admin --format=json
     *     wp onesmtp diagnostics --user=admin --section=queue --format=table
     *
     * Intended for trusted operators running WP-CLI as a WordPress user with
     * the OneSMTP manage capability or manage_options.
     *
     * @param array<int,string> $args Positional arguments.
     * @param array<string,mixed> $assocArgs Associated arguments.
     */
    public function __invoke(array $args = [], array $assocArgs = []): void
    {
        $this->report($args, $assocArgs);
    }

    /**
     * Outputs privacy-safe diagnostics.
     *
     * ## OPTIONS
     *
     * [--section=<section>]
     * : Report section. Accepted values: report, providers, queue, failures. Default: report.
     *
     * [--format=<format>]
     * : Output format. Accepted values: json, table. Default: json.
     *
     * ## EXAMPLES
     *
     *     wp onesmtp diagnostics --user=admin --section=providers --format=json
     *
     * @param array<int,string> $args Positional arguments.
     * @param array<string,mixed> $assocArgs Associated arguments.
     */
    public function report(array $args = [], array $assocArgs = []): void
    {
        $this->assertAuthorized();

        $section = $this->section($assocArgs);
        if ($section === 'providers') {
            $this->providers($args, $assocArgs);

            return;
        }

        if ($section === 'queue') {
            $this->queue($args, $assocArgs);

            return;
        }

        if ($section === 'failures') {
            $this->failures($args, $assocArgs);

            return;
        }

        $report = $this->reportGenerator->generate();
        $format = $this->format($assocArgs);

        if ($format === self::FORMAT_TABLE) {
            $this->emitTable(
                [
                    [
                        'generated_at' => (string) ($report['generated_at'] ?? ''),
                        'provider_count' => (int) ($report['plugin']['provider_count'] ?? 0),
                        'active_provider_count' => (int) ($report['plugin']['active_provider_count'] ?? 0),
                        'queue_status' => (string) ($report['queue']['queue_status'] ?? ''),
                        'scheduler_available' => (bool) ($report['queue']['scheduler_available'] ?? false),
                        'overdue_retry_count' => (int) ($report['queue']['overdue_retry_count'] ?? 0),
                        'failure_category_count' => count((array) ($report['recent_failures']['categories'] ?? [])),
                        'redaction_status' => (string) ($report['redaction']['status'] ?? ''),
                    ],
                ],
                [
                    'generated_at',
                    'provider_count',
                    'active_provider_count',
                    'queue_status',
                    'scheduler_available',
                    'overdue_retry_count',
                    'failure_category_count',
                    'redaction_status',
                ]
            );

            return;
        }

        $this->emitJson($report);
    }

    /**
     * Outputs safe provider status summaries.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Accepted values: json, table. Default: json.
     *
     * @param array<int,string> $args Positional arguments.
     * @param array<string,mixed> $assocArgs Associated arguments.
     */
    public function providers(array $args = [], array $assocArgs = []): void
    {
        $this->assertAuthorized();

        $providers = $this->reportGenerator->providers();

        if ($this->format($assocArgs) === self::FORMAT_TABLE) {
            $this->emitTable(
                $providers,
                ['id', 'slug', 'name', 'adapter_type', 'is_active', 'priority', 'weight', 'circuit_state', 'circuit_until']
            );

            return;
        }

        $this->emitJson($providers);
    }

    /**
     * Outputs safe queue and scheduler status.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Accepted values: json, table. Default: json.
     *
     * @param array<int,string> $args Positional arguments.
     * @param array<string,mixed> $assocArgs Associated arguments.
     */
    public function queue(array $args = [], array $assocArgs = []): void
    {
        $this->assertAuthorized();

        $queue = $this->reportGenerator->queue();

        if ($this->format($assocArgs) === self::FORMAT_TABLE) {
            $this->emitTable(
                [$queue],
                [
                    'scheduler_available',
                    'queue_status',
                    'queued_count',
                    'retry_scheduled_count',
                    'retrying_count',
                    'failed_count',
                    'overdue_retry_count',
                    'next_retry_at',
                ]
            );

            return;
        }

        $this->emitJson($queue);
    }

    /**
     * Outputs recent safe failure category counts.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Accepted values: json, table. Default: json.
     *
     * @param array<int,string> $args Positional arguments.
     * @param array<string,mixed> $assocArgs Associated arguments.
     */
    public function failures(array $args = [], array $assocArgs = []): void
    {
        $this->assertAuthorized();

        $failures = $this->reportGenerator->recentFailures();
        $categories = (array) ($failures['categories'] ?? []);

        if ($this->format($assocArgs) === self::FORMAT_TABLE) {
            $this->emitTable($categories, ['category', 'count', 'last_seen_at']);

            return;
        }

        $this->emitJson($failures);
    }

    private static function isWpCliAvailable(): bool
    {
        return defined('WP_CLI') && (bool) constant('WP_CLI') && class_exists('WP_CLI');
    }

    private function assertAuthorized(): void
    {
        if (Capabilities::canManage()) {
            return;
        }

        \WP_CLI::error('OneSMTP diagnostics require a WP-CLI user with the manage_onesmtp capability or manage_options.');
    }

    /**
     * @param array<string,mixed> $assocArgs
     */
    private function format(array $assocArgs): string
    {
        $format = isset($assocArgs['format']) ? sanitize_key((string) $assocArgs['format']) : self::FORMAT_JSON;

        if (! in_array($format, [self::FORMAT_JSON, self::FORMAT_TABLE], true)) {
            \WP_CLI::error('Invalid OneSMTP diagnostics format. Use json or table.');
        }

        return $format;
    }

    /**
     * @param array<string,mixed> $assocArgs
     */
    private function section(array $assocArgs): string
    {
        $section = isset($assocArgs['section']) ? sanitize_key((string) $assocArgs['section']) : 'report';

        if (! in_array($section, ['report', 'providers', 'queue', 'failures'], true)) {
            \WP_CLI::error('Invalid OneSMTP diagnostics section. Use report, providers, queue, or failures.');
        }

        return $section;
    }

    /**
     * @param mixed $data
     */
    private function emitJson(mixed $data): void
    {
        $encoded = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            \WP_CLI::error('Unable to encode OneSMTP diagnostics output.');
        }

        \WP_CLI::line($encoded);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $columns
     */
    private function emitTable(array $rows, array $columns): void
    {
        \WP_CLI::line(implode("\t", $columns));

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->stringValue($row[$column] ?? null);
            }

            \WP_CLI::line(implode("\t", $values));
        }
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || $value === '') {
            return 'none';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : 'unavailable';
    }
}
