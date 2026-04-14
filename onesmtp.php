<?php
/**
 * Plugin Name: OneSMTP
 * Description: Enterprise-ready email deliverability orchestration with provider failover, rotation, and retry control.
 * Version: 0.1.0
 * Author: OneSMTP
 * Text Domain: onesmtp
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('ONESMTP_VERSION', '0.1.0');
define('ONESMTP_FILE', __FILE__);
define('ONESMTP_PATH', plugin_dir_path(__FILE__));
define('ONESMTP_URL', plugin_dir_url(__FILE__));

require_once ONESMTP_PATH . 'src/Autoloader.php';

\OneSMTP\Autoloader::register();

register_activation_hook(ONESMTP_FILE, ['\\OneSMTP\\Core\\Installer', 'activate']);

add_action('plugins_loaded', static function (): void {
    $plugin = new \OneSMTP\Plugin();
    $plugin->boot();
});
