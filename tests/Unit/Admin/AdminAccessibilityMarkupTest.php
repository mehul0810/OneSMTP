<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\AdminPage;
use OneSMTP\Admin\LogAdmin;
use OneSMTP\Admin\MailConflictNotice;
use OneSMTP\Admin\ProviderAdmin;
use OneSMTP\Admin\QueueDiagnosticsAdmin;
use OneSMTP\Admin\SchedulerNotice;
use OneSMTP\Admin\SettingsAdmin;
use OneSMTP\Admin\SetupWizard;
use OneSMTP\Conflict\MailConflictDetectorInterface;
use OneSMTP\Core\Capabilities;
use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class AdminAccessibilityMarkupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['pagenow'] = 'admin.php';
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            Capabilities::MANAGE_PLUGIN => true,
            Capabilities::VIEW_LOGS => true,
            Capabilities::RESEND_EMAILS => true,
            'manage_options' => false,
        ];
        $GLOBALS['onesmtp_test_options'] = [
            'admin_email' => [
                'value' => 'admin@example.test',
                'autoload' => true,
            ],
        ];
        $GLOBALS['onesmtp_test_object_cache'] = [];
        $GLOBALS['onesmtp_test_transients'] = [];
        unset($GLOBALS['onesmtp_test_wp_die'], $GLOBALS['onesmtp_test_redirect']);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['onesmtp_test_current_user_caps'],
            $GLOBALS['onesmtp_test_options'],
            $GLOBALS['onesmtp_test_object_cache'],
            $GLOBALS['onesmtp_test_transients'],
            $GLOBALS['onesmtp_test_wp_die'],
            $GLOBALS['onesmtp_test_redirect']
        );

        parent::tearDown();
    }

    public function test_admin_sections_expose_labeled_keyboard_reachable_navigation(): void
    {
        $html = $this->capture(static fn (): null => (new AdminPage())->render());

        self::assertStringContainsString('<nav class="nav-tab-wrapper" aria-label="OneSMTP sections">', $html);
        self::assertStringContainsString('data-onesmtp-workspace-link="onesmtp-general"', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('href="https://example.org/wp-admin/admin.php?page=onesmtp#onesmtp-general"', $html);
        self::assertStringContainsString('href="https://example.org/wp-admin/admin.php?page=onesmtp#onesmtp-providers"', $html);
        self::assertStringContainsString('href="https://example.org/wp-admin/admin.php?page=onesmtp#onesmtp-routing"', $html);
        self::assertStringContainsString('href="https://example.org/wp-admin/admin.php?page=onesmtp#onesmtp-logs"', $html);
        self::assertStringContainsString('href="https://example.org/wp-admin/admin.php?page=onesmtp#onesmtp-tools"', $html);
        self::assertStringContainsString('id="onesmtp-setup"', $html);
        self::assertStringContainsString('id="onesmtp-settings"', $html);
        self::assertStringContainsString('id="onesmtp-diagnostics"', $html);
        self::assertStringContainsString('id="onesmtp-alerts"', $html);
        self::assertStringContainsString('aria-label="General / Setup workspace context"', $html);
        self::assertStringContainsString('id="onesmtp-general-heading" tabindex="-1"', $html);
    }

    public function test_provider_controls_have_table_semantics_contextual_actions_and_long_content_wrapping(): void
    {
        $GLOBALS['wpdb']->activeProviders = [
            [
                'id' => 7,
                'slug' => 'primary',
                'name' => 'Primary SMTP',
                'adapter_type' => 'smtp',
                'priority' => 10,
                'weight' => 2,
                'is_active' => 1,
                'config_json' => wp_json_encode([
                    'host' => str_repeat('smtp-segment-', 30) . 'example.test',
                    'password' => 'plain-password',
                ]),
            ],
        ];

        $html = $this->capture(static fn (): null => (new ProviderAdmin(new ProviderRepository()))->render());

        self::assertStringContainsString('<th scope="col">Name</th>', $html);
        self::assertStringContainsString('<th scope="row">Primary SMTP<br><code>primary</code></th>', $html);
        self::assertStringContainsString('style="max-width:32em;white-space:normal;word-break:break-word;"', $html);
        self::assertStringContainsString('aria-label="Deactivate provider Primary SMTP"', $html);
        self::assertStringContainsString('aria-label="Delete provider Primary SMTP"', $html);
        self::assertStringContainsString('<label for="onesmtp-provider-name">Name</label>', $html);
        self::assertStringContainsString('<label for="onesmtp-provider-adapter_type">Provider type</label>', $html);
        self::assertStringNotContainsString('plain-password', $html);
    }

    public function test_log_filters_tables_export_pagination_and_resend_controls_are_labeled(): void
    {
        $_GET = [
            'onesmtp_message_id' => '99',
            'onesmtp_log_page' => '2',
            'onesmtp_logs_per_page' => '25',
        ];
        $GLOBALS['wpdb']->filteredMessageCount = 60;
        $GLOBALS['wpdb']->messageRowsById[99] = [
            'id' => 99,
            'message_uuid' => 'lineage-99',
            'payload_json' => wp_json_encode(['to' => 'person@example.net', 'message' => 'Private body']),
            'status' => 'failed',
            'selected_provider_id' => 7,
            'current_attempt' => 2,
            'max_attempts' => 6,
        ];
        $GLOBALS['wpdb']->recentMessageRows = [
            $GLOBALS['wpdb']->messageRowsById[99] + [
                'attempt_count' => 2,
                'created_at' => '2026-06-24 10:00:00',
                'updated_at' => '2026-06-24 10:05:00',
            ],
        ];
        $GLOBALS['wpdb']->attemptHistoryByMessage[99] = [
            [
                'attempt_no' => 1,
                'provider_id' => 7,
                'trigger_type' => 'initial',
                'result' => 'fail',
                'error_code' => 'provider_failed',
                'error_message' => str_repeat('timeout ', 60),
                'failure_category' => 'timeout',
                'provider_message_id' => 'provider-message-abcdef1234567890',
                'created_at' => '2026-06-24 10:01:00',
            ],
        ];
        $GLOBALS['wpdb']->activeProviders = [
            ['id' => 7, 'name' => 'Primary SMTP', 'adapter_type' => 'smtp', 'is_active' => 1, 'priority' => 1, 'weight' => 1],
        ];
        $GLOBALS['wpdb']->providerRowsById[7] = $GLOBALS['wpdb']->activeProviders[0];

        $html = $this->capture(static fn (): null => (new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository()))->render());

        self::assertStringContainsString('<label for="onesmtp-log-status">Status</label>', $html);
        self::assertStringContainsString('<label for="onesmtp-log-provider">Provider</label>', $html);
        self::assertStringContainsString('<label for="onesmtp-log-search">Search</label>', $html);
        self::assertStringContainsString('aria-label="Export filtered log CSV"', $html);
        self::assertStringContainsString('aria-label="View log entry #99 details"', $html);
        self::assertStringContainsString('aria-label="Previous log page"', $html);
        self::assertStringContainsString('aria-label="Next log page"', $html);
        self::assertStringContainsString('<th scope="col">Message</th>', $html);
        self::assertStringContainsString('<th scope="row"><a href=', $html);
        self::assertStringContainsString('<th scope="col">Safe error context</th>', $html);
        self::assertStringContainsString('<th scope="row">1</th>', $html);
        self::assertStringContainsString('<label for="onesmtp-resend-provider">Provider override</label>', $html);
        self::assertStringContainsString('Resend message', $html);
        self::assertStringContainsString('style="max-width:32em;white-space:normal;word-break:break-word;"', $html);
        self::assertStringNotContainsString('Private body', $html);
        self::assertStringNotContainsString('person@example.net', $html);
    }

    public function test_settings_setup_dashboard_and_diagnostics_keep_labeled_fields_and_safe_states(): void
    {
        $html = $this->capture(static function (): void {
            (new SetupWizard(new ProviderRepository()))->render();
            (new SettingsAdmin())->render();
            (new QueueDiagnosticsAdmin(new QueueDiagnostics(new AlwaysAvailableSchedulerHealth(), new MessageRepository())))->render();
        });

        self::assertStringContainsString('<label for="onesmtp-setup-from_email">Sender email</label>', $html);
        self::assertStringContainsString('<label for="onesmtp-setup-adapter_type">Provider type</label>', $html);
        self::assertStringContainsString('onesmtp-setup-shell', $html);
        self::assertStringContainsString('onesmtp-setup-rail', $html);
        self::assertStringContainsString('onesmtp-setup-panel postbox', $html);
        self::assertStringContainsString('<label for="from_email">From Email</label>', $html);
        self::assertStringContainsString('<legend>Force settings</legend>', $html);
        self::assertStringContainsString('<label><input type="checkbox" name="failure_alert_email_enabled"', $html);
        self::assertStringContainsString('Failure alerts are disabled until an email recipient or HTTPS webhook is enabled.', $html);
        self::assertStringContainsString('<th scope="row">Scheduler availability</th>', $html);
        self::assertStringNotContainsString('secret-token', $html);
        self::assertStringNotContainsString('payload_json', $html);
    }

    public function test_admin_notices_use_specific_link_or_button_text_without_sensitive_payloads(): void
    {
        $GLOBALS['wpdb']->activeProviders = [];

        $html = $this->capture(static function (): void {
            (new SchedulerNotice(new AlwaysAvailableSchedulerHealth()))->render();
            (new MailConflictNotice(new StaticConflictDetector()))->render();
        });

        self::assertStringContainsString('Configure providers', $html);
        self::assertStringContainsString('href="https://example.org/wp-admin/admin.php?page=onesmtp#onesmtp-providers"', $html);
        self::assertStringContainsString('Remind me later', $html);
        self::assertStringContainsString('aria-label="Remind me later about the OneSMTP mail conflict notice"', $html);
        self::assertStringNotContainsString('api_key', $html);
        self::assertStringNotContainsString('payload_json', $html);
    }

    /**
     * @param callable():void $callback
     */
    private function capture(callable $callback): string
    {
        ob_start();
        try {
            $callback();

            return (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw $throwable;
        }
    }
}

final class AlwaysAvailableSchedulerHealth extends ActionSchedulerHealth
{
    public function isAvailable(): bool
    {
        return true;
    }
}

final class StaticConflictDetector implements MailConflictDetectorInterface
{
    /**
     * @return array{plugins:list<string>,hooks:array<string,int>}
     */
    public function detect(): array
    {
        return [
            'plugins' => ['SMTP Test Plugin'],
            'hooks' => ['phpmailer_init' => 1],
        ];
    }
}
