<?php
/**
 * Deterministic network-admin fixture for browser proof without a live site.
 *
 * @package AculectMail
 */

// phpcs:disable

declare(strict_types=1);

use OneSMTP\Admin\NetworkAdmin;
use OneSMTP\Core\Capabilities;
use OneSMTP\Multisite\NetworkLogRepository;
use OneSMTP\Multisite\NetworkSettingsRepository;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Tests\Support\FakeWpdb;

$repoRoot = dirname(__DIR__, 3);

if (! defined('ONESMTP_PATH')) {
    define('ONESMTP_PATH', $repoRoot . '/');
}

require_once $repoRoot . '/src/Autoloader.php';
\OneSMTP\Autoloader::register();
require_once $repoRoot . '/tests/Support/FakeWpdb.php';
require_once $repoRoot . '/tests/bootstrap.php';

$mode = strtolower(trim((string) getenv('ONESMTP_PLAYWRIGHT_NETWORK_MODE')));
$mode = in_array($mode, ['deny', 'empty', 'long'], true) ? $mode : 'pro';
$query = [];
parse_str((string) getenv('ONESMTP_PLAYWRIGHT_NETWORK_QUERY'), $query);

$GLOBALS['onesmtp_test_multisite'] = true;
$GLOBALS['onesmtp_test_network_admin'] = true;
$GLOBALS['onesmtp_test_current_blog_id'] = 1;
$GLOBALS['onesmtp_test_blog_stack'] = [];
$GLOBALS['onesmtp_test_blog_names'] = [
    1 => 'Primary Site',
    2 => $mode === 'long' ? 'Store Site & ' . str_repeat('Long name ', 16) : 'Store Site',
];
$GLOBALS['onesmtp_test_sites'] = [1, 2];
$GLOBALS['onesmtp_test_current_user_caps'] = [
    'manage_network_options' => true,
];
$GLOBALS['onesmtp_test_options'] = [];
$GLOBALS['onesmtp_test_object_cache'] = [];

$wpdb = new FakeWpdb();
$wpdb->activeProviders = [[
    'id' => 7,
    'slug' => 'network-smoke-smtp',
    'name' => 'Network Smoke SMTP',
    'adapter_type' => 'smtp',
    'priority' => 10,
    'weight' => 1,
    'is_active' => 1,
    'circuit_state' => 'closed',
    'circuit_until' => null,
    'config_json' => wp_json_encode([
        'host' => 'smtp.private.test',
        'username' => 'network-smoke',
        'password' => 'fixture-network-secret-never-rendered',
    ]),
    'created_at' => '2026-08-10 10:00:00',
    'updated_at' => '2026-08-10 10:00:00',
]];

$makeMessage = static function (int $id, int $siteId, bool $long): array {
    $uuid = $long
        ? 'network-message-' . $siteId . '-' . str_repeat('very-long-safe-identifier-', 5) . $id
        : 'network-message-' . $siteId . '-' . $id;
    $sourceName = $long ? 'Checkout integration ' . str_repeat('long label ', 12) : 'Checkout integration';

    return [
        'id' => $id,
        'message_uuid' => $uuid,
        'payload_json' => wp_json_encode([
            'to' => ['private-recipient@example.test', 'second@example.test'],
            'subject' => 'Private subject never rendered',
            'message' => 'Private body never rendered',
            'headers' => ['Authorization: Bearer fixture-network-token-never-rendered'],
            'onesmtp_source' => [
                'type' => 'plugin',
                'name' => $sourceName,
            ],
        ]),
        'status' => $id % 2 === 0 ? 'failed' : 'sent',
        'selected_provider_id' => 7,
        'current_attempt' => 2,
        'max_attempts' => 6,
        'attempt_count' => 2,
        'switch_count' => $id % 3 === 0 ? 1 : 0,
        'created_at' => sprintf('2026-08-10 10:%02d:00', $id % 60),
        'updated_at' => sprintf('2026-08-10 10:%02d:30', $id % 60),
    ];
};

$rows = [];
if ($mode === 'long') {
    for ($index = 1; $index <= 75; $index++) {
        $message = $makeMessage(2000 + $index, 2, true);
        $matchesFilters = $index <= 25;
        $message['status'] = $matchesFilters ? 'failed' : 'sent';
        $message['message_uuid'] = ($matchesFilters ? 'needle-' : 'other-') . $message['message_uuid'];
        $rows[] = $message;
    }
} elseif ($mode !== 'empty') {
    $rows = [
        $makeMessage(101, 1, false),
        $makeMessage(202, 2, false),
    ];
}
$wpdb->recentMessageRows = $rows;
$GLOBALS['wpdb'] = $wpdb;

$_GET = array_merge([
    'page' => 'onesmtp-network',
    'tab' => 'settings',
], $query);
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

$featureGate = $mode === 'deny'
    ? new FeatureGate([], true)
    : new FeatureGate([FeatureGate::MULTISITE_MANAGEMENT => true], true);
$settings = new NetworkSettingsRepository($featureGate);
$logs = new NetworkLogRepository($settings, $featureGate);
$admin = new NetworkAdmin($settings, $logs, $featureGate);

try {
    ob_start();
    $admin->render();
    $body = (string) ob_get_clean();
} catch (Throwable $error) {
    ob_end_clean();
    $body = '<main class="onesmtp-network-denied" role="main"><h1>Aculect Mail Network</h1><div class="notice notice-error"><p>'
        . esc_html($error->getMessage())
        . '</p></div></main>';
}

echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>Aculect Mail Network Admin Smoke Fixture</title><style>';
echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:24px;background:#f0f0f1;color:#1d2327}';
echo '.wrap{margin:0 auto;max-width:1280px}.postbox{background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 1px rgba(0,0,0,.04);margin-top:18px}';
echo '.postbox-header{border-bottom:1px solid #dcdcde;padding:0 16px}.postbox-header h2{font-size:1.15rem}.inside{padding:16px}';
echo '.nav-tab-wrapper{margin-bottom:18px}.nav-tab{color:#135e96}.nav-tab-active{background:#fff;color:#1d2327}';
echo '.widefat{border-collapse:collapse;width:100%;margin:12px 0}.widefat th,.widefat td{border:1px solid #ccd0d4;padding:8px;text-align:left;vertical-align:top}';
echo '.notice{border-left:4px solid #72aee6;padding:8px 12px;margin:12px 0;background:#fff}.notice-info{border-left-color:#72aee6}.notice-error{border-left-color:#d63638}';
echo '.regular-text{min-width:22rem}.button{cursor:pointer}.tablenav-pages{display:flex;gap:12px;align-items:center}.onesmtp-network-admin form{margin:0 0 18px}.onesmtp-network-admin input,.onesmtp-network-admin select{max-width:100%;box-sizing:border-box}';
echo '.onesmtp-network-admin fieldset{border:1px solid #dcdcde;padding:14px;margin:14px 0}.onesmtp-network-admin label{line-height:1.8}';
echo '@media(max-width:782px){body{margin:12px}.wrap{max-width:none}.regular-text{min-width:0;width:100%}.onesmtp-network-admin .inside{padding:12px}.onesmtp-network-admin form[method="get"] label,.onesmtp-network-admin form[method="get"] input,.onesmtp-network-admin form[method="get"] select{display:block;margin:6px 0}.onesmtp-network-admin .widefat{min-width:900px}.onesmtp-network-admin .postbox{overflow:hidden}.onesmtp-network-admin .nav-tab{display:block;margin:4px 0}.onesmtp-network-admin fieldset label{display:block}}';
$adminStyles = $repoRoot . '/assets/admin.css';
if (file_exists($adminStyles)) {
    echo file_get_contents($adminStyles);
}
echo '</style></head><body>' . $body . '</body></html>';
