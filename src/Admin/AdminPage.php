<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Settings\SenderIdentityRepository;

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
    private ProviderRepository $providerRepository;
    private SenderIdentityRepository $senderIdentityRepository;

    public function __construct(
        ?ProviderAdmin $providers = null,
        ?SetupWizard $setupWizard = null,
        ?LogAdmin $logs = null,
        ?SettingsAdmin $settings = null,
        ?QueueDiagnosticsAdmin $diagnostics = null,
        ?DashboardAdmin $dashboard = null,
        ?AlertHistoryAdmin $alerts = null,
        ?ProviderRepository $providerRepository = null,
        ?SenderIdentityRepository $senderIdentityRepository = null
    )
    {
        $this->providerRepository = $providerRepository ?? new ProviderRepository();
        $this->senderIdentityRepository = $senderIdentityRepository ?? new SenderIdentityRepository();
        $this->dashboard = $dashboard ?? new DashboardAdmin();
        $this->providers = $providers ?? new ProviderAdmin($this->providerRepository);
        $this->setupWizard = $setupWizard ?? new SetupWizard($this->providerRepository);
        $this->logs = $logs ?? new LogAdmin(new MessageRepository(), new AttemptRepository(), $this->providerRepository);
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
        $activeProviders = $this->providerRepository->getActiveProviders();
        $senderIdentity = $this->senderIdentityRepository->get()->toArray();

        echo '<div class="wrap onesmtp-admin">';
        $this->renderHero($activeProviders, $senderIdentity);
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
                'id' => 'onesmtp-general',
                'title' => esc_html__('General / Setup', 'onesmtp'),
                'description' => esc_html__('Review setup health, sender identity, and the first-run path before moving on to providers or tools.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-general',
            ],
            [
                'id' => 'onesmtp-providers',
                'title' => esc_html__('Providers', 'onesmtp'),
                'description' => esc_html__('Manage delivery providers, priority, weights, active state, and safe provider actions.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-providers',
            ],
            [
                'id' => 'onesmtp-routing',
                'title' => esc_html__('Email Control / Routing', 'onesmtp'),
                'description' => esc_html__('Tune sender defaults, routing behavior, failover safeguards, and privacy-safe alerting without overpromising features that are not in scope.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-routing',
            ],
            [
                'id' => 'onesmtp-logs',
                'title' => esc_html__('Email Logs', 'onesmtp'),
                'description' => esc_html__('Review delivery history, provider outcomes, retries, and safe follow-up actions from a log-first workspace.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-logs',
            ],
            [
                'id' => 'onesmtp-tools',
                'title' => esc_html__('Tools', 'onesmtp'),
                'description' => esc_html__('Use tools to test, diagnose, export, clean up, debug, or reset delivery operations.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-tools',
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

        if ($section['id'] === 'onesmtp-general') {
            $this->renderAnchorAlias('onesmtp-dashboard');
            $this->renderAnchorAlias('onesmtp-setup');
            $this->dashboard->render();
            $this->setupWizard->render();
        } elseif ($section['id'] === 'onesmtp-providers') {
            $this->providers->render();
        } elseif ($section['id'] === 'onesmtp-routing') {
            $this->renderAnchorAlias('onesmtp-settings');
            $this->settings->render();
        } elseif ($section['id'] === 'onesmtp-logs') {
            $this->logs->render();
        } elseif ($section['id'] === 'onesmtp-tools') {
            $this->renderAnchorAlias('onesmtp-diagnostics');
            $this->renderAnchorAlias('onesmtp-alerts');
            $this->diagnostics->render();
            $this->alerts->render();
        } else {
            echo '<p>' . esc_html($section['description']) . '</p>';
        }

        echo '</div>';
        echo '</section>';
    }

    /**
     * @param array<int,array<string,mixed>> $activeProviders
     * @param array<string,mixed> $senderIdentity
     */
    private function renderHero(array $activeProviders, array $senderIdentity): void
    {
        $primaryProvider = $activeProviders[0] ?? null;
        $setupReady = $this->isSetupReady($senderIdentity, $activeProviders);
        $providerCount = count($activeProviders);
        $senderEmail = trim((string) ($senderIdentity['from_email'] ?? ''));
        $senderName = trim((string) ($senderIdentity['from_name'] ?? ''));

        echo '<header class="onesmtp-admin-hero">';
        echo '<div class="onesmtp-admin-hero-main">';
        echo '<div class="onesmtp-admin-brand-mark" aria-hidden="true"><span class="dashicons dashicons-email-alt2"></span></div>';
        echo '<div class="onesmtp-admin-hero-copy">';
        echo '<h1 class="onesmtp-admin-hero-title">' . esc_html__('OneSMTP', 'onesmtp') . '</h1>';
        echo '<p class="onesmtp-admin-hero-tagline">' . esc_html__('Reliable email delivery for WordPress.', 'onesmtp') . '</p>';
        echo '<p class="onesmtp-admin-hero-summary">' . esc_html__('A premium, enterprise-ready admin workspace for setup, routing, logs, diagnostics, and recovery without drifting away from WordPress-native patterns.', 'onesmtp') . '</p>';
        echo '<div class="onesmtp-admin-hero-actions">';
        echo '<a class="button button-primary" href="#onesmtp-general">' . esc_html__('Continue setup', 'onesmtp') . '</a>';
        echo '<a class="button button-secondary" href="#onesmtp-providers">' . esc_html__('Review providers', 'onesmtp') . '</a>';
        echo '<a class="button button-secondary" href="#onesmtp-logs">' . esc_html__('Open logs', 'onesmtp') . '</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<aside class="onesmtp-admin-hero-rail" aria-label="' . esc_attr__('OneSMTP status and quick actions', 'onesmtp') . '">';
        $this->renderHeroCard(
            __('Setup health', 'onesmtp'),
            $setupReady ? __('Ready to test', 'onesmtp') : __('Needs setup', 'onesmtp'),
            $setupReady
                ? sprintf(
                    /* translators: 1: sender email, 2: sender name. */
                    __('Sender identity is configured as %1$s%2$s and OneSMTP can now be verified with a test send.', 'onesmtp'),
                    $senderEmail !== '' ? $senderEmail : __('not configured', 'onesmtp'),
                    $senderName !== '' ? ' / ' . $senderName : ''
                )
                : __('Complete sender identity and activate at least one provider to finish setup.', 'onesmtp'),
            '#onesmtp-general',
            __('Finish setup', 'onesmtp')
        );

        $this->renderHeroCard(
            __('Current provider', 'onesmtp'),
            $primaryProvider !== null ? sprintf(
                '%s · %s',
                (string) ($primaryProvider['name'] ?? __('Unknown provider', 'onesmtp')),
                str_replace('_', ' ', sanitize_key((string) ($primaryProvider['adapter_type'] ?? 'unknown')))
            ) : __('No active providers', 'onesmtp'),
            $providerCount > 0
                ? sprintf(
                    /* translators: %d: active provider count. */
                    $providerCount === 1
                        ? __('%d active provider in the current stack.', 'onesmtp')
                        : __('%d active providers in the current stack.', 'onesmtp'),
                    $providerCount
                )
                : __('Add a provider to unlock failover and routing behavior.', 'onesmtp'),
            '#onesmtp-providers',
            __('Manage providers', 'onesmtp')
        );

        $this->renderHeroCard(
            __('Retention', 'onesmtp'),
            sprintf(
                /* translators: %d: retention days. */
                __('%d-day log retention', 'onesmtp'),
                \OneSMTP\Core\RetentionPolicy::getLogRetentionDays()
            ),
            __('Logs and attachment metadata follow the current privacy-safe retention policy.', 'onesmtp'),
            '#onesmtp-tools',
            __('Review tools', 'onesmtp')
        );

        $this->renderHeroCard(
            __('Docs and references', 'onesmtp'),
            __('Use the current docs and validation contract', 'onesmtp'),
            __('Keep implementation and review aligned with the repo docs, testing gates, and release guidance.', 'onesmtp'),
            '#onesmtp-routing',
            __('Open routing', 'onesmtp')
        );
        echo '</aside>';
        echo '</header>';
    }

    private function renderHeroCard(string $title, string $value, string $description, string $href, string $buttonLabel): void
    {
        echo '<section class="onesmtp-admin-hero-card">';
        echo '<div class="onesmtp-admin-hero-card-copy">';
        echo '<p class="onesmtp-admin-hero-card-title">' . esc_html($title) . '</p>';
        echo '<p class="onesmtp-admin-hero-card-value">' . esc_html($value) . '</p>';
        echo '<p class="onesmtp-admin-hero-card-description">' . esc_html($description) . '</p>';
        echo '</div>';
        echo '<a class="button button-secondary" href="' . esc_url($href) . '">' . esc_html($buttonLabel) . '</a>';
        echo '</section>';
    }

    private function renderAnchorAlias(string $id): void
    {
        echo '<span id="' . esc_attr($id) . '" class="screen-reader-text" aria-hidden="true"></span>';
    }

    /**
     * @param array<string,mixed> $senderIdentity
     * @param array<int,array<string,mixed>> $activeProviders
     */
    private function isSetupReady(array $senderIdentity, array $activeProviders): bool
    {
        $fromEmail = trim((string) ($senderIdentity['from_email'] ?? ''));
        $fromName = trim((string) ($senderIdentity['from_name'] ?? ''));

        return $fromEmail !== '' && $fromName !== '' && $activeProviders !== [];
    }
}
