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
    private const COMPONENT_SCRIPT_PATH = 'build/index.js';
    private const COMPONENT_STYLE_PATH = 'build/index.css';

    private DashboardAdmin $dashboard;
    private ProviderAdmin $providers;
    private SetupWizard $setupWizard;
    private LogAdmin $logs;
    private SettingsAdmin $settings;
    private QueueDiagnosticsAdmin $diagnostics;
    private AlertHistoryAdmin $alerts;
    private ProviderRepository $providerRepository;
    private SenderIdentityRepository $senderIdentityRepository;
    private AdminScreenRegistry $screenRegistry;

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
        $this->screenRegistry = $this->buildScreenRegistry();
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
        // Studio and some WordPress admin routers use a different hook suffix
        // for Settings submenu pages. The page query is the stable boundary.
        if ($page !== self::MENU_SLUG) {
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

        $componentPath = rtrim($pluginPath, '/\\') . '/' . self::COMPONENT_SCRIPT_PATH;
        if (file_exists($componentPath)) {
            wp_enqueue_script(
                'onesmtp-components',
                rtrim($pluginUrl, '/\\') . '/' . self::COMPONENT_SCRIPT_PATH,
                ['wp-components', 'wp-element', 'wp-i18n'],
                (string) filemtime($componentPath),
                true
            );
        }

        $componentStylePath = rtrim($pluginPath, '/\\') . '/' . self::COMPONENT_STYLE_PATH;
        if (file_exists($componentStylePath)) {
            wp_enqueue_style(
                'onesmtp-components',
                rtrim($pluginUrl, '/\\') . '/' . self::COMPONENT_STYLE_PATH,
                ['wp-components'],
                (string) filemtime($componentStylePath)
            );
        }
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'options-general.php',
            esc_html__('OneSMTP', 'onesmtp'),
            esc_html__('OneSMTP', 'onesmtp'),
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
        $activeScreen = $this->screenRegistry->resolve($this->requestedScreenId()) ?? $this->screenRegistry->resolve('onesmtp-overview');
        $activeProviders = $this->providerRepository->getActiveProviders();
        $senderIdentity = $this->senderIdentityRepository->get()->toArray();
        $setupReady = $this->isSetupReady($senderIdentity, $activeProviders);

        echo '<div class="wrap onesmtp-admin" data-onesmtp-workspaces>';
        $this->renderHeader($sections, $setupReady, $activeScreen?->id() ?? 'onesmtp-overview');
        echo '<hr class="wp-header-end">';
        echo '<div class="onesmtp-admin-shell">';

        foreach ($this->screenRegistry->all() as $screen) {
            $this->renderSection($screen, $activeProviders, $senderIdentity, $screen->id() === ($activeScreen?->id() ?? 'onesmtp-overview'));
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * @return array<int,array{id:string,title:string,description:string,href:string}>
     */
    private function sections(): array
    {
        return array_map(function (AdminScreenDefinition $screen): array {
            $id = $screen->id();
            $href = admin_url('options-general.php?page=' . self::MENU_SLUG . '&tab=' . rawurlencode($id) . '#' . $id);

            return [
                'id' => $id,
                'title' => esc_html($screen->title()),
                'description' => esc_html($screen->description()),
                'href' => $href,
            ];
        }, $this->screenRegistry->all());
    }

    /**
     * @param AdminScreenDefinition $screen
     */
    private function renderSection(AdminScreenDefinition $screen, array $activeProviders, array $senderIdentity, bool $isActive): void
    {
        $sectionId = $screen->id();
        $headingId = $sectionId . '-heading';

        echo '<section id="' . esc_attr($sectionId) . '" class="onesmtp-admin-section" data-onesmtp-workspace="' . esc_attr($sectionId) . '" aria-labelledby="' . esc_attr($headingId) . '"' . ($isActive ? '' : ' hidden') . '>';
        echo '<header class="onesmtp-admin-section-header">';
        echo '<div class="onesmtp-admin-section-heading">';
        echo '<h2 id="' . esc_attr($headingId) . '" tabindex="-1">' . esc_html($screen->title()) . '</h2>';
        echo '<p class="onesmtp-admin-section-description">' . esc_html($screen->description()) . '</p>';
        echo '</div>';
        echo '</header>';
        echo '<div class="onesmtp-admin-workspace-layout is-full-width' . ($sectionId === 'onesmtp-overview' ? ' is-overview' : '') . '">';
        echo '<div class="onesmtp-admin-section-body">';
        $screen->render($activeProviders, $senderIdentity);

        echo '</div>';
        echo '</div>';
        echo '</section>';
    }

    /**
     * @param array<int,array{id:string,title:string,description:string,href:string}> $sections
     */
    private function renderHeader(array $sections, bool $setupReady, string $activeScreenId): void
    {
        echo '<header class="onesmtp-admin-header">';
        echo '<div class="onesmtp-admin-header-top">';
        echo '<div class="onesmtp-admin-brand">';
        echo '<div class="onesmtp-admin-brand-mark" aria-hidden="true">' . Heroicons::render('envelope') . '</div>';
        echo '<div class="onesmtp-admin-brand-copy">';
        echo '<h1>' . esc_html__('OneSMTP', 'onesmtp') . '</h1>';
        echo '<p>' . esc_html__('Reliable email delivery for WordPress.', 'onesmtp') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '<div class="onesmtp-admin-header-status">';
        echo '<span class="onesmtp-admin-status-dot ' . ($setupReady ? 'is-ready' : 'needs-setup') . '" aria-hidden="true"></span>';
        echo '<div><span class="onesmtp-admin-status-label">' . esc_html__('Delivery readiness', 'onesmtp') . '</span>';
        echo '<span class="onesmtp-admin-status ' . ($setupReady ? 'is-ready' : 'needs-setup') . '">';
        echo esc_html($setupReady ? __('Setup ready', 'onesmtp') : __('Setup needed', 'onesmtp'));
        echo '</span></div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="onesmtp-admin-nav">';
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('OneSMTP sections', 'onesmtp') . '">';

        foreach ($sections as $section) {
            $isActive = $section['id'] === $activeScreenId;
            $className = $isActive ? 'nav-tab nav-tab-active' : 'nav-tab';
            $ariaCurrent = $isActive ? 'page' : '';
            echo '<a class="' . esc_attr($className) . '" data-onesmtp-workspace-link="' . esc_attr($section['id']) . '" href="' . esc_url($section['href']) . '"' . ($ariaCurrent !== '' ? ' aria-current="' . esc_attr($ariaCurrent) . '"' : '') . '>' . esc_html($section['title']) . '</a>';
        }

        echo '</nav>';
        echo '</div>';
        echo '</header>';
    }

    /**
     * @param array<int,array<string,mixed>> $activeProviders
     */
    private function renderRoutingOverview(array $activeProviders): void
    {
        $primary = $activeProviders[0]['name'] ?? __('Primary provider', 'onesmtp');
        echo '<section class="onesmtp-routing-card"><h3>' . esc_html__('Default delivery route', 'onesmtp') . '</h3><div class="onesmtp-route-diagram">';
        foreach ([['wordpress', __('WordPress', 'onesmtp')], ['envelope', (string) $primary], ['envelope', __('Email', 'onesmtp')]] as $index => [$icon, $label]) {
            if ($index > 0) echo '<span class="onesmtp-route-line" aria-hidden="true"></span>';
            echo '<div class="onesmtp-route-node"><span>' . Heroicons::render($icon === 'wordpress' ? 'squares' : 'envelope') . '</span><strong>' . esc_html($label) . '</strong></div>';
        }
        echo '</div><p class="onesmtp-route-empty">' . esc_html($activeProviders === [] ? __('Choose a provider to create your first route.', 'onesmtp') : __('Your primary provider will receive messages by default.', 'onesmtp')) . '</p><a class="button button-primary" href="' . esc_url($this->normalizeInternalHref('#onesmtp-providers')) . '">' . esc_html__('Select provider', 'onesmtp') . '</a></section>';
        echo '<section class="onesmtp-routing-rules"><h3>' . esc_html__('Routing rules', 'onesmtp') . '</h3><div class="onesmtp-empty-state onesmtp-empty-state--inline">' . Heroicons::render('squares') . '<div><strong>' . esc_html__('No routing rules yet', 'onesmtp') . '</strong><p>' . esc_html__('Create rules to send certain emails through specific providers.', 'onesmtp') . '</p><a href="' . esc_url($this->normalizeInternalHref('#onesmtp-settings')) . '">' . esc_html__('Learn about routing rules', 'onesmtp') . '</a></div></div></section>';
    }

    private function normalizeInternalHref(string $href): string
    {
        if (! str_starts_with($href, '#')) {
            return $href;
        }

        $target = ltrim($href, '#');
        $screen = $this->screenRegistry->resolve($target);
        $screenId = $screen?->id() ?? 'onesmtp-overview';

        return admin_url('options-general.php?page=' . self::MENU_SLUG . '&tab=' . rawurlencode($screenId) . '#' . $target);
    }

    private function requestedScreenId(): string
    {
        if (! isset($_GET['tab'])) {
            return 'onesmtp-overview';
        }

        return sanitize_key(wp_unslash((string) $_GET['tab']));
    }

    private function buildScreenRegistry(): AdminScreenRegistry
    {
        $registry = new AdminScreenRegistry();
        $registry->register(new AdminScreenDefinition(
            'onesmtp-overview',
            __('Overview', 'onesmtp'),
            __('Check readiness and complete the next step toward reliable WordPress email delivery.', 'onesmtp'),
            function (): void {
                $this->renderAnchorAlias('onesmtp-general');
                $this->renderAnchorAlias('onesmtp-setup');
                $this->setupWizard->render();
            },
            ['onesmtp-general', 'onesmtp-setup', 'onesmtp-delivery']
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-providers',
            __('Providers', 'onesmtp'),
            __('Manage delivery providers, priority, weights, active state, and safe provider actions.', 'onesmtp'),
            function (): void {
                $this->providers->render();
            }
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-routing',
            __('Routing', 'onesmtp'),
            __('Understand and tune provider selection, weighted delivery, routing rules, and automatic failover behavior.', 'onesmtp'),
            function (array $activeProviders): void {
                $this->renderRoutingOverview($activeProviders);
            }
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-activity',
            __('Activity', 'onesmtp'),
            __('Review recent email delivery events from OneSMTP.', 'onesmtp'),
            function (): void {
                $this->renderAnchorAlias('onesmtp-logs');
                $this->logs->render();
            },
            ['onesmtp-logs']
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-analytics',
            __('Analytics', 'onesmtp'),
            __('Compare delivery health, provider performance, failover activity, and future cost-efficiency signals.', 'onesmtp'),
            function (): void {
                $this->renderAnchorAlias('onesmtp-dashboard');
                $this->dashboard->render();
            },
            ['onesmtp-dashboard']
        ));
        $registry->register(new AdminScreenDefinition(
            'onesmtp-settings',
            __('Settings', 'onesmtp'),
            __('Configure sender identity, delivery controls, alerts, retention, diagnostics, and safe settings transfer.', 'onesmtp'),
            function (): void {
                $this->renderAnchorAlias('onesmtp-tools');
                $this->renderAnchorAlias('onesmtp-diagnostics');
                $this->renderAnchorAlias('onesmtp-alerts');
            $this->settings->render();
            echo '<details class="onesmtp-admin-secondary-panel"><summary>' . esc_html__('Queue diagnostics', 'onesmtp') . '</summary>';
            $this->diagnostics->render();
            echo '</details>';
            echo '<details class="onesmtp-admin-secondary-panel"><summary>' . esc_html__('Alert history', 'onesmtp') . '</summary>';
            $this->alerts->render();
            echo '</details>';
            },
            ['onesmtp-tools', 'onesmtp-diagnostics', 'onesmtp-alerts']
        ));

        return $registry;
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
