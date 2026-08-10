<?php

declare(strict_types=1);

use OneSMTP\Admin\AdminPage;
use OneSMTP\Admin\DashboardAdmin;
use OneSMTP\Admin\QueueDiagnosticsAdmin;
use OneSMTP\Analytics\ProviderReliabilityScorer;
use OneSMTP\Core\Capabilities;
use OneSMTP\Diagnostics\DiagnosticReportGenerator;
use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;

$repoRoot = dirname(__DIR__, 3);

if (! defined('ONESMTP_PATH')) {
    define('ONESMTP_PATH', $repoRoot . '/');
}

require_once $repoRoot . '/src/Autoloader.php';
\OneSMTP\Autoloader::register();
require_once $repoRoot . '/tests/Support/FakeWpdb.php';
require_once $repoRoot . '/tests/bootstrap.php';

$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['pagenow'] = 'options-general.php';
$GLOBALS['onesmtp_test_current_user_caps'] = [
    Capabilities::MANAGE_PLUGIN => true,
    Capabilities::VIEW_LOGS => true,
    Capabilities::RESEND_EMAILS => true,
];
$GLOBALS['onesmtp_test_options'] = [
    'admin_email' => [
        'value' => 'admin@example.test',
        'autoload' => true,
    ],
];
$GLOBALS['onesmtp_test_object_cache'] = [];

$provider = [
    'id' => 7,
    'slug' => 'browser_smoke_smtp',
    'name' => 'Browser Smoke SMTP',
    'adapter_type' => 'smtp',
    'priority' => 10,
    'weight' => 1,
    'is_active' => 1,
    'circuit_state' => 'closed',
    'circuit_until' => null,
    'config_json' => wp_json_encode([
        'host' => 'smtp.local.test',
        'port' => '2525',
        'username' => 'browser-smoke',
        'password' => 'fixture-password-never-rendered',
        'from_email' => 'admin@example.test',
        'from_name' => 'Admin Sender',
    ]),
    'created_at' => '2026-06-26 10:00:00',
    'updated_at' => '2026-06-26 10:00:00',
];

$GLOBALS['wpdb']->activeProviders = [$provider];
$GLOBALS['wpdb']->providerRowsById[7] = $provider;
$GLOBALS['wpdb']->queueDiagnosticRow = [
    'queued_count' => 0,
    'retry_scheduled_count' => 4,
    'retrying_count' => 0,
    'failed_count' => 1,
    'overdue_retry_count' => 3,
    'next_retry_at' => '2026-06-26 10:30:00',
    'payload_json' => '{"message":"customer body","token":"secret-token"}',
];
$GLOBALS['wpdb']->failureCategoryRowsBySince['2026-06-25 12:00:00'] = [
    [
        'failure_category' => 'provider_timeout',
        'failure_count' => 3,
    ],
];

$message = [
    'id' => 21,
    'message_uuid' => 'lineage-smoke-21',
    'payload_json' => wp_json_encode([
        'to' => ['recipient@example.test'],
        'subject' => 'Smoke subject',
        'message' => 'Internal smoke body',
        'attachments' => ['/var/www/private/invoice.pdf'],
    ]),
    'status' => 'failed',
    'selected_provider_id' => 7,
    'current_attempt' => 1,
    'max_attempts' => 6,
    'created_at' => '2026-06-26 10:00:00',
    'updated_at' => '2026-06-26 10:01:00',
];

$GLOBALS['wpdb']->messageRowsById[21] = $message;
$GLOBALS['wpdb']->recentMessageRows = [$message + ['attempt_count' => 1]];
$GLOBALS['wpdb']->attemptHistoryByMessage[21] = [
    [
        'id' => 31,
        'message_id' => 21,
        'attempt_no' => 1,
        'provider_id' => 7,
        'trigger_type' => 'initial',
        'result' => 'fail',
        'error_code' => 'provider_timeout',
        'error_message' => str_repeat('transient timeout ', 18),
        'failure_category' => 'provider_timeout',
        'latency_ms' => 900,
        'provider_message_id' => 'provider-message-21',
        'created_at' => '2026-06-26 10:01:00',
    ],
];
$GLOBALS['wpdb']->eventRows = [
    45 => [
        'id' => 45,
        'event_type' => 'terminal_failure',
        'actor_id' => null,
        'message_id' => 21,
        'provider_id' => 7,
        'context_json' => wp_json_encode([
            'summary' => 'Terminal failure for message #21 after retry boundary.',
            'reason' => 'max_retries_boundary',
            'failure_category' => 'provider_timeout',
            'attempt' => 6,
            'recipient_count' => 1,
            'recipient_domains' => ['example.test'],
            'provider' => [
                'id' => 7,
                'name' => 'Browser Smoke SMTP',
            ],
            'payload_token' => 'token=fixture-alert-token-never-rendered',
            'provider_secret' => 'fixture-provider-secret-never-rendered',
        ]),
        'created_at' => '2026-06-26 10:05:00',
    ],
    44 => [
        'id' => 44,
        'event_type' => 'terminal_failure',
        'actor_id' => null,
        'message_id' => 20,
        'provider_id' => 7,
        'context_json' => wp_json_encode([
            'summary' => 'Terminal failure already acknowledged for message #20.',
            'reason' => 'provider_pool_exhausted',
            'failure_category' => 'configuration',
            'attempt' => 3,
            'recipient_count' => 2,
            'recipient_domains' => ['example.test'],
            'api_key' => 'fixture-api-key-never-rendered',
        ]),
        'created_at' => '2026-06-26 09:45:00',
    ],
];
$GLOBALS['wpdb']->eventAcknowledgementRows = [
    [
        'id' => 50,
        'actor_id' => 42,
        'context_json' => wp_json_encode([
            'alert_event_id' => 44,
            'alert_event_type' => 'terminal_failure',
            'alert_status' => 'acknowledged',
            'summary' => 'Acknowledged alert event #44.',
            'alert_context' => [
                'reason' => 'provider_pool_exhausted',
                'recipient_count' => 2,
                'recipient_domains' => ['example.test'],
            ],
            'authorization' => 'Bearer fixture-authorization-never-rendered',
        ]),
        'created_at' => '2026-06-26 09:50:00',
    ],
];

$analyticsSince = '2026-06-19 12:00:00';
$GLOBALS['wpdb']->dashboardActivityRowsBySince[$analyticsSince] = [
    'sent_count' => 96,
    'failed_count' => 4,
    'retry_count' => 2,
];
$GLOBALS['wpdb']->dashboardProviderAttemptRowsBySince[$analyticsSince] = [[
    'provider_id' => 7,
    'provider_name' => 'Browser Smoke SMTP',
    'adapter_type' => 'smtp',
    'sent_count' => 96,
    'failed_count' => 4,
    'retry_count' => 2,
    'avg_latency_ms' => 800,
]];
$analyticsUntil = '2026-06-26 12:00:00';
$analyticsWindowKey = $analyticsSince . '|' . $analyticsUntil;
$GLOBALS['wpdb']->advancedProviderRowsByWindow[$analyticsWindowKey] = [[
    'provider_id' => 7,
    'provider_name' => 'Browser Smoke SMTP',
    'adapter_type' => 'smtp',
    'sent_count' => 96,
    'failed_count' => 4,
    'retry_count' => 2,
    'attempt_count' => 100,
    'avg_latency_ms' => 800,
]];
$GLOBALS['wpdb']->advancedStatusRowsByWindow[$analyticsWindowKey] = [
    ['status' => 'sent', 'status_count' => 18],
    ['status' => 'failed', 'status_count' => 2],
];
$GLOBALS['wpdb']->advancedSubjectRowsByWindow[$analyticsWindowKey] = [
    ['subject' => 'Smoke report token=fixture-secret-never-rendered', 'subject_count' => 2],
    ['subject' => '', 'subject_count' => 1],
];
$GLOBALS['wpdb']->advancedTrendRowsByWindow[$analyticsWindowKey] = [
    ['period' => '2026-06-24', 'status' => 'sent', 'status_count' => 18],
];
$GLOBALS['wpdb']->advancedFailureRowsByWindow[$analyticsWindowKey] = [
    ['failure_category' => 'timeout', 'failure_count' => 2, 'last_seen_at' => '2026-06-25 10:00:00'],
];

$_GET = [
    'page' => 'onesmtp',
    'onesmtp_message_id' => '21',
];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

$queue = new QueueDiagnostics(
    new class extends ActionSchedulerHealth {
        public function isAvailable(): bool
        {
            return false;
        }
    },
    new MessageRepository(),
    static fn (): int => 1782475200
);

$proFeatures = new FeatureGate([FeatureGate::ADVANCED_ANALYTICS => true], true);
$admin = new AdminPage(
    diagnostics: new QueueDiagnosticsAdmin(
        $queue,
        new DiagnosticReportGenerator(new ProviderRepository(), $queue, new AttemptRepository(), null, static fn (): int => 1782475200)
    ),
    dashboard: new DashboardAdmin(
        nowProvider: static fn (): int => 1782475200,
        reliability: new ProviderReliabilityScorer(),
        features: $proFeatures
    )
);

ob_start();
$admin->render();
$body = (string) ob_get_clean();

echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
echo '<title>Aculect Mail Admin Smoke Fixture</title>';
echo '<style>';
echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:24px;background:#f0f0f1;color:#1d2327}';
echo '.wrap{margin:0}';
echo '.postbox{background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 1px rgba(0,0,0,.04)}';
echo '.nav-tab{color:#135e96}.widefat{border-collapse:collapse;width:100%;margin:12px 0}';
echo '.widefat th,.widefat td{border:1px solid #ccd0d4;padding:8px;text-align:left;vertical-align:top}';
echo '.notice{border-left:4px solid #72aee6;padding:8px 12px;margin:12px 0}';
echo '.notice-warning{border-left-color:#dba617}.notice-success{border-left-color:#00a32a}';
echo '.notice-info{border-left-color:#72aee6}.notice-error{border-left-color:#d63638}';
echo '.regular-text{min-width:22rem}.large-text{width:100%}.button{cursor:pointer}';
$adminStyles = $repoRoot . '/assets/admin.css';
if (file_exists($adminStyles)) {
    echo file_get_contents($adminStyles);
}
echo '</style>';
echo '</head><body>';
echo $body;
echo '<script>';
$adminScript = $repoRoot . '/assets/admin.js';
if (file_exists($adminScript)) {
    echo file_get_contents($adminScript); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture asset.
}
echo '</script>';
echo '</body></html>';
