<?php

declare(strict_types=1);

namespace {
    if (! defined('WP_CLI')) {
        define('WP_CLI', true);
    }

    if (! class_exists('WP_CLI')) {
        final class WP_CLI
        {
            /** @var array<int,array{name:string,command:mixed}> */
            public static array $commands = [];

            /** @var array<int,string> */
            public static array $lines = [];

            public static function add_command(string $name, mixed $command): void
            {
                self::$commands[] = [
                    'name' => $name,
                    'command' => $command,
                ];
            }

            public static function line(string $message): void
            {
                self::$lines[] = $message;
            }

            public static function error(string $message): void
            {
                throw new \RuntimeException($message);
            }

            public static function reset(): void
            {
                self::$commands = [];
                self::$lines = [];
            }
        }
    }
}

namespace OneSMTP\Tests\Unit\Cli {
    use OneSMTP\Cli\DiagnosticsCommand;
    use OneSMTP\Diagnostics\DiagnosticReportGenerator;
    use OneSMTP\Plugin;
    use OneSMTP\Queue\ActionSchedulerHealth;
    use OneSMTP\Queue\QueueDiagnostics;
    use OneSMTP\Repository\AttemptRepository;
    use OneSMTP\Repository\MessageRepository;
    use OneSMTP\Repository\ProviderRepository;
    use OneSMTP\Tests\Support\FakeWpdb;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class DiagnosticsCommandTest extends TestCase
    {
        private const NOW = 1782302400;
        private const SINCE = '2026-06-17 12:00:00';

        protected function setUp(): void
        {
            parent::setUp();

            \WP_CLI::reset();

            $GLOBALS['wpdb'] = new FakeWpdb();
            $GLOBALS['onesmtp_test_current_user_can'] = true;
            $GLOBALS['onesmtp_test_options'] = [];
            $GLOBALS['onesmtp_test_actions'] = [];
            $GLOBALS['onesmtp_test_admin_menu_pages'] = [];
            $GLOBALS['onesmtp_test_admin_submenu_pages'] = [];
        }

        public function test_register_adds_wp_cli_command_group(): void
        {
            DiagnosticsCommand::register($this->command(true));

            self::assertSame('onesmtp diagnostics', \WP_CLI::$commands[0]['name']);
            self::assertIsCallable(\WP_CLI::$commands[0]['command']);
        }

        public function test_plugin_boot_registers_wp_cli_diagnostics_command_when_available(): void
        {
            $this->seedDiagnosticRows();

            (new Plugin())->boot();

            $names = array_column(\WP_CLI::$commands, 'name');

            self::assertContains('onesmtp diagnostics', $names);
        }

        public function test_report_outputs_privacy_safe_json(): void
        {
            $this->seedDiagnosticRows();

            $this->command(true)->report([], ['format' => 'json']);

            $output = implode("\n", \WP_CLI::$lines);
            $decoded = json_decode($output, true);

            self::assertIsArray($decoded);
            self::assertSame('2026-06-24T12:00:00Z', $decoded['generated_at']);
            self::assertSame('sendgrid', $decoded['providers'][0]['adapter_type']);
            self::assertSame('[excluded]', $decoded['providers'][0]['config_values']);
            self::assertSame('healthy', $decoded['queue']['queue_status']);
            self::assertSame('authentication', $decoded['recent_failures']['categories'][0]['category']);
            self::assertSame('applied', $decoded['redaction']['status']);

            self::assertStringNotContainsString('raw-api-key', $output);
            self::assertStringNotContainsString('secret-token', $output);
            self::assertStringNotContainsString('provider-token', $output);
            self::assertStringNotContainsString('customer@example.test', $output);
            self::assertStringNotContainsString('Fixture message body', $output);
            self::assertStringNotContainsString('https://hooks.example.test', $output);
        }

        public function test_report_section_option_outputs_focused_views(): void
        {
            $this->seedDiagnosticRows();

            $this->command(true)->report([], ['section' => 'queue', 'format' => 'table']);

            $output = implode("\n", \WP_CLI::$lines);

            self::assertStringContainsString("scheduler_available\tqueue_status\tqueued_count", $output);
            self::assertStringContainsString("true\thealthy\t1", $output);
        }

        public function test_focused_views_support_script_friendly_tables_without_sensitive_values(): void
        {
            $this->seedDiagnosticRows();

            $command = $this->command(false);
            $command->providers([], ['format' => 'table']);
            $command->queue([], ['format' => 'table']);
            $command->failures([], ['format' => 'table']);

            $output = implode("\n", \WP_CLI::$lines);

            self::assertStringContainsString("id\tslug\tname\tadapter_type", $output);
            self::assertStringContainsString("scheduler_available\tqueue_status\tqueued_count", $output);
            self::assertStringContainsString("category\tcount\tlast_seen_at", $output);
            self::assertStringContainsString('authentication', $output);
            self::assertStringContainsString('false', $output);
            self::assertStringNotContainsString('raw-api-key', $output);
            self::assertStringNotContainsString('secret-token', $output);
            self::assertStringNotContainsString('https://hooks.example.test', $output);
        }

        public function test_diagnostics_require_manage_capability(): void
        {
            $GLOBALS['onesmtp_test_current_user_can'] = false;

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('manage_onesmtp');

            $this->command(true)->report([], ['format' => 'json']);
        }

        public function test_invalid_format_fails_before_output(): void
        {
            $this->seedDiagnosticRows();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Use json or table');

            $this->command(true)->queue([], ['format' => 'xml']);
        }

        private function seedDiagnosticRows(): void
        {
            $GLOBALS['wpdb']->activeProviders = [
                [
                    'id' => 7,
                    'slug' => 'primary_api',
                    'name' => 'Primary API token=provider-token',
                    'adapter_type' => 'sendgrid',
                    'priority' => 10,
                    'weight' => 2,
                    'is_active' => 1,
                    'circuit_state' => 'closed',
                    'circuit_until' => null,
                    'config_json' => wp_json_encode(
                        [
                            'api_key' => 'raw-api-key',
                            'webhook_url' => 'https://hooks.example.test/secret-token',
                            'safe_label' => 'customer@example.test',
                        ]
                    ),
                ],
            ];
            $GLOBALS['wpdb']->queueDiagnosticRow = [
                'queued_count' => 1,
                'retry_scheduled_count' => 2,
                'retrying_count' => 0,
                'failed_count' => 3,
                'overdue_retry_count' => 0,
                'next_retry_at' => '2026-06-24 12:30:00',
                'payload_json' => '{"message":"Fixture message body","api_key":"abc123"}',
            ];
            $GLOBALS['wpdb']->failureCategoryRowsBySince[self::SINCE] = [
                [
                    'failure_category' => 'authentication',
                    'failure_count' => 3,
                    'last_seen_at' => '2026-06-24 12:00:00',
                ],
            ];
        }

        private function command(bool $schedulerAvailable): DiagnosticsCommand
        {
            $queue = new QueueDiagnostics($this->health($schedulerAvailable), new MessageRepository(), static fn (): int => self::NOW);

            return new DiagnosticsCommand(
                new DiagnosticReportGenerator(
                    new ProviderRepository(),
                    $queue,
                    new AttemptRepository(),
                    null,
                    static fn (): int => self::NOW
                )
            );
        }

        private function health(bool $available): ActionSchedulerHealth
        {
            return new class($available) extends ActionSchedulerHealth {
                private bool $available;

                public function __construct(bool $available)
                {
                    $this->available = $available;
                }

                public function isAvailable(): bool
                {
                    return $this->available;
                }
            };
        }
    }
}
