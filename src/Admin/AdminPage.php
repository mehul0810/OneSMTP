<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;

final class AdminPage
{
    private const MENU_SLUG = 'onesmtp';
    private const ADMIN_STYLE_HANDLE = 'onesmtp-admin';
    private const ADMIN_STYLE_PATH = 'assets/admin.css';

    private DashboardAdmin $dashboard;
    private ProviderAdmin $providers;
    private SetupWizard $setupWizard;
    private LogAdmin $logs;
    private SettingsAdmin $settings;
    private QueueDiagnosticsAdmin $diagnostics;
    private AlertHistoryAdmin $alerts;

    public function __construct(?ProviderAdmin $providers = null, ?SetupWizard $setupWizard = null, ?LogAdmin $logs = null, ?SettingsAdmin $settings = null, ?QueueDiagnosticsAdmin $diagnostics = null, ?DashboardAdmin $dashboard = null, ?AlertHistoryAdmin $alerts = null)
    {
        $this->dashboard = $dashboard ?? new DashboardAdmin();
        $this->providers = $providers ?? new ProviderAdmin(new ProviderRepository());
        $this->setupWizard = $setupWizard ?? new SetupWizard(new ProviderRepository());
        $this->logs = $logs ?? new LogAdmin(new MessageRepository(), new AttemptRepository(), new ProviderRepository());
        $this->settings = $settings ?? new SettingsAdmin();
        $this->diagnostics = $diagnostics ?? new QueueDiagnosticsAdmin();
        $this->alerts = $alerts ?? new AlertHistoryAdmin();
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this->setupWizard, 'handleRequest']);
        add_action('admin_init', [$this->providers, 'handleRequest']);
        add_action('admin_init', [$this->logs, 'handleRequest']);
        add_action('admin_init', [$this->settings, 'handleRequest']);
        add_action('admin_init', [$this->diagnostics, 'handleRequest']);
        add_action('admin_init', [$this->alerts, 'handleRequest']);
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
        if ($page !== self::MENU_SLUG || $hookSuffix !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        if (! function_exists('wp_enqueue_style')) {
            return;
        }

        $pluginPath = defined('ONESMTP_PATH') ? (string) constant('ONESMTP_PATH') : '';
        $pluginUrl = defined('ONESMTP_URL') ? (string) constant('ONESMTP_URL') : '';
        if ($pluginPath === '' || $pluginUrl === '') {
            return;
        }

        $path = rtrim($pluginPath, '/\\') . '/' . self::ADMIN_STYLE_PATH;
        if (! file_exists($path)) {
            return;
        }

        wp_enqueue_style(
            self::ADMIN_STYLE_HANDLE,
            rtrim($pluginUrl, '/\\') . '/' . self::ADMIN_STYLE_PATH,
            [],
            (string) filemtime($path)
        );
    }

    public function registerMenu(): void
    {
        add_menu_page(
            esc_html__('OneSMTP', 'onesmtp'),
            esc_html__('OneSMTP', 'onesmtp'),
            Capabilities::MANAGE_PLUGIN,
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-email-alt2',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            esc_html__('Settings', 'onesmtp'),
            esc_html__('Settings', 'onesmtp'),
            Capabilities::MANAGE_PLUGIN,
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to access OneSMTP settings.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $sections = $this->sections();

        echo '<div class="wrap onesmtp-admin">';
        echo '<header class="onesmtp-admin-header">';
        echo '<div class="onesmtp-admin-heading">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('OneSMTP', 'onesmtp') . '</h1>';
        echo '<p class="onesmtp-admin-intro">' . esc_html__('Manage WordPress mail delivery, provider setup, diagnostics, and delivery safeguards from one admin workspace built around native WordPress patterns.', 'onesmtp') . '</p>';
        echo '</div>';
        echo '</header>';
        echo '<hr class="wp-header-end">';
        echo '<div class="onesmtp-admin-nav">';
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('OneSMTP sections', 'onesmtp') . '">';

        foreach ($sections as $section) {
            echo '<a class="nav-tab" href="' . esc_url($section['href']) . '">' . esc_html($section['title']) . '</a>';
        }

        echo '</nav>';
        echo '</div>';
        echo '<div class="onesmtp-admin-shell">';

        foreach ($sections as $section) {
            $this->renderSection($section);
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * @return array<int,array{id:string,title:string,description:string,href:string}>
     */
    private function sections(): array
    {
        $baseUrl = admin_url('admin.php?page=' . self::MENU_SLUG);

        return [
            [
                'id' => 'onesmtp-dashboard',
                'title' => esc_html__('Dashboard', 'onesmtp'),
                'description' => esc_html__('Review aggregate delivery, pending queue, retry, and failover activity.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-dashboard',
            ],
            [
                'id' => 'onesmtp-setup',
                'title' => esc_html__('Setup', 'onesmtp'),
                'description' => esc_html__('Complete first-run setup for sender identity, provider configuration, test email verification, and setup log confirmation.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-setup',
            ],
            [
                'id' => 'onesmtp-providers',
                'title' => esc_html__('Providers', 'onesmtp'),
                'description' => esc_html__('Manage delivery providers, priority, weights, and activation state.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-providers',
            ],
            [
                'id' => 'onesmtp-logs',
                'title' => esc_html__('Logs', 'onesmtp'),
                'description' => esc_html__('Email delivery log views will appear here when the logging interface is available.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-logs',
            ],
            [
                'id' => 'onesmtp-diagnostics',
                'title' => esc_html__('Diagnostics', 'onesmtp'),
                'description' => esc_html__('Review scheduler availability, queue status, overdue retries, and recovery actions.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-diagnostics',
            ],
            [
                'id' => 'onesmtp-alerts',
                'title' => esc_html__('Alerts', 'onesmtp'),
                'description' => esc_html__('Review alert event history and acknowledgement status.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-alerts',
            ],
            [
                'id' => 'onesmtp-settings',
                'title' => esc_html__('Settings', 'onesmtp'),
                'description' => esc_html__('Configure sender defaults, delivery safeguards, alert routing, reporting, and safe settings transfer controls.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-settings',
            ],
        ];
    }

    /**
     * @param array{id:string,title:string,description:string,href:string} $section
     */
    private function renderSection(array $section): void
    {
        $headingId = $section['id'] . '-heading';

        echo '<section id="' . esc_attr($section['id']) . '" class="onesmtp-admin-section" aria-labelledby="' . esc_attr($headingId) . '">';
        echo '<header class="onesmtp-admin-section-header">';
        echo '<div class="onesmtp-admin-section-heading">';
        echo '<h2 id="' . esc_attr($headingId) . '">' . esc_html($section['title']) . '</h2>';
        echo '<p class="onesmtp-admin-section-description">' . esc_html($section['description']) . '</p>';
        echo '</div>';
        echo '</header>';
        echo '<div class="onesmtp-admin-section-body">';

        if ($section['id'] === 'onesmtp-dashboard') {
            $this->dashboard->render();
        } elseif ($section['id'] === 'onesmtp-setup') {
            $this->setupWizard->render();
        } elseif ($section['id'] === 'onesmtp-providers') {
            $this->providers->render();
        } elseif ($section['id'] === 'onesmtp-logs') {
            $this->logs->render();
        } elseif ($section['id'] === 'onesmtp-alerts') {
            $this->alerts->render();
        } elseif ($section['id'] === 'onesmtp-settings') {
            $this->settings->render();
        } elseif ($section['id'] === 'onesmtp-diagnostics') {
            $this->diagnostics->render();
        } else {
            echo '<p>' . esc_html($section['description']) . '</p>';
        }

        echo '</div>';
        echo '</section>';
    }
}
