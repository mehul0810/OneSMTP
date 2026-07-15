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
    private const ADMIN_SCRIPT_HANDLE = 'onesmtp-admin';
    private const ADMIN_SCRIPT_PATH = 'assets/admin.js';

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

        if (! function_exists('wp_enqueue_script')) {
            return;
        }

        $scriptPath = rtrim($pluginPath, '/\\') . '/' . self::ADMIN_SCRIPT_PATH;
        if (! file_exists($scriptPath)) {
            return;
        }

        wp_enqueue_script(
            self::ADMIN_SCRIPT_HANDLE,
            rtrim($pluginUrl, '/\\') . '/' . self::ADMIN_SCRIPT_PATH,
            [],
            (string) filemtime($scriptPath),
            true
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
        $setupReady = $this->isSetupReady($senderIdentity, $activeProviders);

        echo '<div class="wrap onesmtp-admin" data-onesmtp-workspaces>';
        $this->renderHeader($sections, $setupReady);
        echo '<hr class="wp-header-end">';
        echo '<div class="onesmtp-admin-shell">';

        foreach ($sections as $section) {
            $this->renderSection($section, $activeProviders, $senderIdentity);
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
    private function renderSection(array $section, array $activeProviders, array $senderIdentity): void
    {
        $headingId = $section['id'] . '-heading';

        echo '<section id="' . esc_attr($section['id']) . '" class="onesmtp-admin-section" data-onesmtp-workspace="' . esc_attr($section['id']) . '" aria-labelledby="' . esc_attr($headingId) . '">';
        echo '<header class="onesmtp-admin-section-header">';
        echo '<div class="onesmtp-admin-section-heading">';
        echo '<h2 id="' . esc_attr($headingId) . '" tabindex="-1">' . esc_html($section['title']) . '</h2>';
        echo '<p class="onesmtp-admin-section-description">' . esc_html($section['description']) . '</p>';
        echo '</div>';
        echo '</header>';
        echo '<div class="onesmtp-admin-workspace-layout">';
        echo '<div class="onesmtp-admin-section-body">';

        if ($section['id'] === 'onesmtp-general') {
            $this->renderAnchorAlias('onesmtp-dashboard');
            $this->dashboard->render();
            $this->renderAnchorAlias('onesmtp-setup');
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
        $this->renderContextualRail($section['id'], $activeProviders, $senderIdentity);
        echo '</div>';
        echo '</section>';
    }

    /**
     * @param array<int,array{id:string,title:string,description:string,href:string}> $sections
     */
    private function renderHeader(array $sections, bool $setupReady): void
    {
        echo '<header class="onesmtp-admin-header">';
        echo '<div class="onesmtp-admin-header-top">';
        echo '<div class="onesmtp-admin-brand">';
        echo '<div class="onesmtp-admin-brand-mark" aria-hidden="true"><span class="dashicons dashicons-email-alt2"></span></div>';
        echo '<div class="onesmtp-admin-brand-copy">';
        echo '<h1>' . esc_html__('OneSMTP', 'onesmtp') . '</h1>';
        echo '<p>' . esc_html__('Reliable email delivery for WordPress.', 'onesmtp') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<span class="onesmtp-admin-status ' . ($setupReady ? 'is-ready' : 'needs-setup') . '">';
        echo esc_html($setupReady ? __('Setup ready', 'onesmtp') : __('Setup needed', 'onesmtp'));
        echo '</span>';
        echo '</div>';
        echo '<div class="onesmtp-admin-nav">';
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('OneSMTP sections', 'onesmtp') . '">';

        foreach ($sections as $index => $section) {
            $className = $index === 0 ? 'nav-tab nav-tab-active' : 'nav-tab';
            $ariaCurrent = $index === 0 ? 'page' : '';
            echo '<a class="' . esc_attr($className) . '" data-onesmtp-workspace-link="' . esc_attr($section['id']) . '" href="' . esc_url($section['href']) . '"' . ($ariaCurrent !== '' ? ' aria-current="' . esc_attr($ariaCurrent) . '"' : '') . '>' . esc_html($section['title']) . '</a>';
        }

        echo '</nav>';
        echo '</div>';
        echo '</header>';
    }

    /**
     * @param array<int,array<string,mixed>> $activeProviders
     * @param array<string,mixed> $senderIdentity
     */
    private function renderContextualRail(string $sectionId, array $activeProviders, array $senderIdentity): void
    {
        $providerCount = count($activeProviders);
        $primaryProvider = $activeProviders[0] ?? null;
        $setupReady = $this->isSetupReady($senderIdentity, $activeProviders);
        $senderReady = trim( (string) ($senderIdentity['from_email'] ?? '') ) !== ''
            && trim( (string) ($senderIdentity['from_name'] ?? '') ) !== '';
        $retention = sprintf(
            /* translators: %d: retention days. */
            __('%d-day retention', 'onesmtp'),
            \OneSMTP\Core\RetentionPolicy::getLogRetentionDays()
        );

        echo '<aside class="onesmtp-context-rail" aria-label="' . esc_attr(sprintf(
            /* translators: %s: workspace name. */
            __('%s workspace context', 'onesmtp'),
            $this->sectionTitle($sectionId)
        )) . '">';

        if ($sectionId === 'onesmtp-general') {
            $this->renderContextCard(
                __('Setup health', 'onesmtp'),
                $setupReady ? __('Ready to test', 'onesmtp') : __('Needs setup', 'onesmtp'),
                $setupReady ? __('Sender identity and an active provider are configured.', 'onesmtp') : __('Complete sender identity and activate a provider.', 'onesmtp'),
                '#onesmtp-setup',
                __('Continue setup', 'onesmtp')
            );
            $this->renderProviderContextCard($providerCount, $primaryProvider, '#onesmtp-providers', __('Manage providers', 'onesmtp'));
        } elseif ($sectionId === 'onesmtp-providers') {
            $this->renderProviderContextCard($providerCount, $primaryProvider, '#onesmtp-providers', __('Add or edit providers', 'onesmtp'));
            $this->renderContextCard(
                __('Failover coverage', 'onesmtp'),
                $providerCount > 1 ? __('Backup available', 'onesmtp') : __('Single-provider stack', 'onesmtp'),
                $providerCount > 1 ? __('Multiple active providers can support failover.', 'onesmtp') : __('Add a second active provider before relying on failover.', 'onesmtp'),
                '#onesmtp-routing',
                __('Review routing', 'onesmtp')
            );
        } elseif ($sectionId === 'onesmtp-routing') {
            $this->renderContextCard(
                __('Sender identity', 'onesmtp'),
                $senderReady ? __('Configured', 'onesmtp') : __('Needs setup', 'onesmtp'),
                $senderReady ? __('Default sender values are available to the routing controls.', 'onesmtp') : __('Set the default sender name and email before enabling force options.', 'onesmtp'),
                '#onesmtp-settings',
                __('Review sender settings', 'onesmtp')
            );
            $this->renderContextCard(
                __('Delivery stack', 'onesmtp'),
                $providerCount > 1 ? __('Failover-ready', 'onesmtp') : __('Limited coverage', 'onesmtp'),
                $providerCount > 1 ? __('Routing has multiple active providers available.', 'onesmtp') : __('Routing currently has fewer than two active providers.', 'onesmtp'),
                '#onesmtp-providers',
                __('Review providers', 'onesmtp')
            );
        } elseif ($sectionId === 'onesmtp-logs') {
            $this->renderContextCard(
                __('Log retention', 'onesmtp'),
                $retention,
                __('Messages and safe attachment metadata follow the current retention policy.', 'onesmtp'),
                '#onesmtp-tools',
                __('Open log tools', 'onesmtp')
            );
            $this->renderProviderContextCard($providerCount, $primaryProvider, '#onesmtp-providers', __('Review providers', 'onesmtp'));
        } elseif ($sectionId === 'onesmtp-tools') {
            $this->renderContextCard(
                __('Operational readiness', 'onesmtp'),
                $setupReady ? __('Configured', 'onesmtp') : __('Setup incomplete', 'onesmtp'),
                $setupReady ? __('Core sender and provider prerequisites are available.', 'onesmtp') : __('Finish setup before treating diagnostics as delivery proof.', 'onesmtp'),
                '#onesmtp-general',
                __('Review setup', 'onesmtp')
            );
            $this->renderContextCard(
                __('Log retention', 'onesmtp'),
                $retention,
                __('Cleanup, export, and diagnostics keep the current privacy-safe retention boundary.', 'onesmtp'),
                '#onesmtp-logs',
                __('Open email logs', 'onesmtp')
            );
        }

        echo '</aside>';
    }

    /**
     * @param array<string,mixed>|null $primaryProvider
     */
    private function renderProviderContextCard(int $providerCount, ?array $primaryProvider, string $href, string $buttonLabel): void
    {
        $providerName = $primaryProvider !== null
            ? (string) ($primaryProvider['name'] ?? __('Unknown provider', 'onesmtp'))
            : __('No active providers', 'onesmtp');
        if ($providerCount === 1) {
            /* translators: %d: active provider count. */
            $availability = __('%d active provider is available.', 'onesmtp');
        } else {
            /* translators: %d: active provider count. */
            $availability = __('%d active providers are available.', 'onesmtp');
        }

        $this->renderContextCard(
            __('Active delivery stack', 'onesmtp'),
            $providerName,
            sprintf($availability, $providerCount),
            $href,
            $buttonLabel
        );
    }

    private function renderContextCard(string $title, string $value, string $description, string $href, string $buttonLabel): void
    {
        echo '<section class="onesmtp-context-card">';
        echo '<div class="onesmtp-context-card-copy">';
        echo '<p class="onesmtp-context-card-title">' . esc_html($title) . '</p>';
        echo '<p class="onesmtp-context-card-value">' . esc_html($value) . '</p>';
        echo '<p class="onesmtp-context-card-description">' . esc_html($description) . '</p>';
        echo '</div>';
        echo '<a class="button button-secondary" href="' . esc_url($href) . '">' . esc_html($buttonLabel) . '</a>';
        echo '</section>';
    }

    private function sectionTitle(string $sectionId): string
    {
        foreach ($this->sections() as $section) {
            if ($section['id'] === $sectionId) {
                return $section['title'];
            }
        }

        return __('OneSMTP', 'onesmtp');
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
