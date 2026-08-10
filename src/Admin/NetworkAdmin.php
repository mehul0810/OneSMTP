<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Multisite\NetworkLogRepository;
use OneSMTP\Multisite\NetworkSettingsRepository;
use OneSMTP\Product\FeatureGate;
use RuntimeException;

final class NetworkAdmin
{
    private const MENU_SLUG = 'onesmtp-network';
    private const SETTINGS_ACTION = 'save_network_settings';
    private const SITE_ACTION = 'save_network_site_settings';
    private const NONCE_ACTION = 'onesmtp_network_settings';
    private const NONCE_NAME = 'onesmtp_network_nonce';
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 50;

    public function __construct(
        private ?NetworkSettingsRepository $settings = null,
        private ?NetworkLogRepository $logs = null,
        private ?FeatureGate $featureGate = null
    ) {
        $this->featureGate = $featureGate ?? FeatureGate::fromRuntime();
        $this->settings = $settings ?? new NetworkSettingsRepository($this->featureGate);
        $this->logs = $logs ?? new NetworkLogRepository($this->settings, $this->featureGate);
    }

    public function registerHooks(): void
    {
        add_action('network_admin_menu', [$this, 'registerMenu']);
        add_action('network_admin_init', [$this, 'handleRequest']);
    }

    public function registerMenu(): void
    {
        if ( ! Capabilities::canManageNetwork($this->featureGate)) {
            return;
        }

        add_submenu_page(
            'settings.php',
            esc_html__('Aculect Mail Network', 'onesmtp'),
            esc_html__('Aculect Mail', 'onesmtp'),
            'manage_network_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function handleRequest(): void
    {
        if ( ! $this->isCurrentPage() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        if ( ! Capabilities::canManageNetwork($this->featureGate)) {
            $this->deny();
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);
        $action = isset($_POST['onesmtp_network_action']) ? sanitize_key(wp_unslash( (string) $_POST['onesmtp_network_action'])) : '';

        if ($action === self::SETTINGS_ACTION) {
            $saved = $this->settings->saveNetwork(
                [
                    NetworkSettingsRepository::RATE_LIMITS => [
                        'per_minute' => $this->postInt('network_rate_limit_per_minute'),
                        'per_hour' => $this->postInt('network_rate_limit_per_hour'),
                        'per_day' => $this->postInt('network_rate_limit_per_day'),
                    ],
                    NetworkSettingsRepository::BACKGROUND_SENDING => ['enabled' => isset($_POST['network_background_sending_enabled'])],
                    NetworkSettingsRepository::ATTACHMENT_LOGGING => ['enabled' => isset($_POST['network_attachment_logging_enabled'])],
                ],
                [
                    NetworkSettingsRepository::RATE_LIMITS => isset($_POST['network_inherit_rate_limits']),
                    NetworkSettingsRepository::BACKGROUND_SENDING => isset($_POST['network_inherit_background_sending']),
                    NetworkSettingsRepository::ATTACHMENT_LOGGING => isset($_POST['network_inherit_attachment_logging']),
                ]
            );
            $this->redirect($saved ? 'network_saved' : 'network_failed');
        }

        if ($action === self::SITE_ACTION) {
            $siteId = $this->postInt('onesmtp_network_site_id');
            $saved = $this->settings->saveSite(
                $siteId,
                [
                    NetworkSettingsRepository::RATE_LIMITS => [
                        'per_minute' => $this->postInt('site_rate_limit_per_minute'),
                        'per_hour' => $this->postInt('site_rate_limit_per_hour'),
                        'per_day' => $this->postInt('site_rate_limit_per_day'),
                    ],
                    NetworkSettingsRepository::BACKGROUND_SENDING => ['enabled' => isset($_POST['site_background_sending_enabled'])],
                    NetworkSettingsRepository::ATTACHMENT_LOGGING => ['enabled' => isset($_POST['site_attachment_logging_enabled'])],
                ],
                [
                    NetworkSettingsRepository::RATE_LIMITS => isset($_POST['site_inherit_rate_limits']),
                    NetworkSettingsRepository::BACKGROUND_SENDING => isset($_POST['site_inherit_background_sending']),
                    NetworkSettingsRepository::ATTACHMENT_LOGGING => isset($_POST['site_inherit_attachment_logging']),
                ]
            );
            $this->redirect($saved ? 'site_saved' : 'site_failed', $siteId);
        }
    }

    public function render(): void
    {
        if ( ! Capabilities::canManageNetwork($this->featureGate)) {
            $this->deny();
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash( (string) $_GET['tab'])) : 'settings';
        $tab = in_array($tab, ['settings', 'logs'], true) ? $tab : 'settings';
        echo '<div class="wrap onesmtp-network-admin">';
        echo '<h1>' . esc_html__('Aculect Mail Network', 'onesmtp') . '</h1>';
        echo '<p>' . esc_html__('Network defaults never include provider credentials, alert destinations, sender recipients, message bodies, or raw headers.', 'onesmtp') . '</p>';
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Aculect Mail network sections', 'onesmtp') . '">';
        foreach ([
			'settings' => __('Network settings', 'onesmtp'),
			'logs' => __('Network logs', 'onesmtp'),
		] as $id => $label) {
            $href = network_admin_url('settings.php?page=' . self::MENU_SLUG . '&tab=' . $id);
            echo '<a class="nav-tab' . ($tab === $id ? ' nav-tab-active' : '') . '" href="' . esc_url($href) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav><hr class="wp-header-end">';
        $this->renderNotice();
        if ($tab === 'logs') {
            $this->renderLogs();
        } else {
            $this->renderSettings();
        }
        echo '</div>';
    }

    private function renderSettings(): void
    {
        $network = $this->settings->getNetwork();
        echo '<section class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html__('Safe network defaults', 'onesmtp') . '</h2></div><div class="inside">';
        echo '<p>' . esc_html__('Choose which operational controls sites inherit. Sites can explicitly opt out and keep their own site-level values.', 'onesmtp') . '</p>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        echo '<input type="hidden" name="onesmtp_network_action" value="' . esc_attr(self::SETTINGS_ACTION) . '">';
        $limits = $network['defaults'][ NetworkSettingsRepository::RATE_LIMITS ] ?? [];
        echo '<fieldset><legend><strong>' . esc_html__('Delivery rate limits', 'onesmtp') . '</strong></legend>';
        $this->numberField('network_rate_limit_per_minute', __('Per minute', 'onesmtp'), (int) ($limits['per_minute'] ?? 0));
        $this->numberField('network_rate_limit_per_hour', __('Per hour', 'onesmtp'), (int) ($limits['per_hour'] ?? 0));
        $this->numberField('network_rate_limit_per_day', __('Per day', 'onesmtp'), (int) ($limits['per_day'] ?? 0));
        $this->checkbox('network_inherit_rate_limits', __('Allow sites to inherit these rate limits', 'onesmtp'), ! empty($network['default_inheritance'][ NetworkSettingsRepository::RATE_LIMITS ]));
        echo '</fieldset>';
        $this->checkbox('network_background_sending_enabled', __('Enable background sending by default', 'onesmtp'), ! empty($network['defaults'][ NetworkSettingsRepository::BACKGROUND_SENDING ]['enabled']));
        $this->checkbox('network_inherit_background_sending', __('Allow sites to inherit background sending', 'onesmtp'), ! empty($network['default_inheritance'][ NetworkSettingsRepository::BACKGROUND_SENDING ]));
        $this->checkbox('network_attachment_logging_enabled', __('Enable attachment metadata logging by default', 'onesmtp'), ! empty($network['defaults'][ NetworkSettingsRepository::ATTACHMENT_LOGGING ]['enabled']));
        $this->checkbox('network_inherit_attachment_logging', __('Allow sites to inherit attachment logging', 'onesmtp'), ! empty($network['default_inheritance'][ NetworkSettingsRepository::ATTACHMENT_LOGGING ]));
        submit_button(__('Save network defaults', 'onesmtp'));
        echo '</form></div></section>';
        $this->renderSiteOverrideForm();
    }

    private function renderSiteOverrideForm(): void
    {
        $siteIds = $this->logs->siteIds();
        echo '<section class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html__('Site inheritance and overrides', 'onesmtp') . '</h2></div><div class="inside">';
        if ($siteIds === []) {
            echo '<p>' . esc_html__('No sites are available for a site-level override.', 'onesmtp') . '</p></div></section>';

            return;
        }

        $siteId = isset($_GET['network_site_id']) ? absint(wp_unslash( (string) $_GET['network_site_id'])) : $siteIds[0];
        if ( ! in_array($siteId, $siteIds, true)) {
            $siteId = $siteIds[0];
        }
        $site = $this->readSite($siteId);
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="settings"><label for="onesmtp-network-site"><strong>' . esc_html__('Site', 'onesmtp') . '</strong></label> <select id="onesmtp-network-site" name="network_site_id">';
        foreach ($siteIds as $availableSiteId) {
            /* translators: %d: site ID. */
            $label = $availableSiteId === $siteId ? $site['name'] : sprintf(__('Site %d', 'onesmtp'), $availableSiteId);
            echo '<option value="' . esc_attr( (string) $availableSiteId) . '"' . ($availableSiteId === $siteId ? ' selected="selected"' : '') . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';
		submit_button(__('Load site', 'onesmtp'), 'secondary', 'submit', false);
		echo '</form>';
        echo '<p>' . esc_html__('Inheritance is explicit per control. Turning inheritance off keeps only this site’s allowlisted values; provider credentials remain site-local.', 'onesmtp') . '</p>';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        echo '<input type="hidden" name="onesmtp_network_action" value="' . esc_attr(self::SITE_ACTION) . '"><input type="hidden" name="onesmtp_network_site_id" value="' . esc_attr( (string) $siteId) . '">';
        $limits = $site['settings']['overrides'][ NetworkSettingsRepository::RATE_LIMITS ] ?? [];
        $inheritance = $site['settings']['inheritance'];
        $this->numberField('site_rate_limit_per_minute', __('Per minute', 'onesmtp'), (int) ($limits['per_minute'] ?? 0));
        $this->numberField('site_rate_limit_per_hour', __('Per hour', 'onesmtp'), (int) ($limits['per_hour'] ?? 0));
        $this->numberField('site_rate_limit_per_day', __('Per day', 'onesmtp'), (int) ($limits['per_day'] ?? 0));
        $this->checkbox('site_inherit_rate_limits', __('Inherit network rate limits', 'onesmtp'), ! array_key_exists(NetworkSettingsRepository::RATE_LIMITS, $inheritance) || ! empty($inheritance[ NetworkSettingsRepository::RATE_LIMITS ]));
        $this->checkbox('site_background_sending_enabled', __('Enable background sending for this site', 'onesmtp'), ! empty($site['settings']['overrides'][ NetworkSettingsRepository::BACKGROUND_SENDING ]['enabled']));
        $this->checkbox('site_inherit_background_sending', __('Inherit network background sending', 'onesmtp'), ! array_key_exists(NetworkSettingsRepository::BACKGROUND_SENDING, $inheritance) || ! empty($inheritance[ NetworkSettingsRepository::BACKGROUND_SENDING ]));
        $this->checkbox('site_attachment_logging_enabled', __('Enable attachment logging for this site', 'onesmtp'), ! empty($site['settings']['overrides'][ NetworkSettingsRepository::ATTACHMENT_LOGGING ]['enabled']));
        $this->checkbox('site_inherit_attachment_logging', __('Inherit network attachment logging', 'onesmtp'), ! array_key_exists(NetworkSettingsRepository::ATTACHMENT_LOGGING, $inheritance) || ! empty($inheritance[ NetworkSettingsRepository::ATTACHMENT_LOGGING ]));
        submit_button(__('Save site override', 'onesmtp'));
        echo '</form></div></section>';
    }

    private function renderLogs(): void
    {
        $filters = $this->filters();
        $page = max(1, absint($_GET['network_log_page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, absint($_GET['network_logs_per_page'] ?? self::DEFAULT_PER_PAGE)));
        try {
            $total = $this->logs->countFiltered($filters);
            $maxPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $maxPage);
            $rows = $this->logs->listFiltered($filters, $page, $perPage);
        } catch (\Throwable $error) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Network logs could not be loaded. Try again or narrow the filter.', 'onesmtp') . '</p></div></div>';

            return;
        }

        echo '<section class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html__('Network delivery logs', 'onesmtp') . '</h2></div><div class="inside">';
        echo '<p>' . esc_html__('Only safe delivery summaries are shown. Message bodies, recipients, headers, provider credentials, and raw payloads stay within each site boundary.', 'onesmtp') . '</p>';
        $this->renderLogFilters($filters, $perPage);
        if ($rows === []) {
            echo '<p class="notice inline notice-info"><strong>' . esc_html($this->hasFilters($filters) ? __('No network messages match these filters.', 'onesmtp') : __('No network email activity yet.', 'onesmtp')) . '</strong></p></section>';

            return;
        }

        /* translators: 1: current page, 2: total pages, 3: bounded log count. */
        echo '<p>' . esc_html(sprintf(__('Page %1$d of %2$d (%3$d bounded log entries)', 'onesmtp'), $page, $maxPage, $total)) . '</p><div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
        foreach (['Site', 'Message', 'Status', 'Provider', 'Attempts', 'Switchovers', 'Source', 'Recipients', 'Attachments', 'Created'] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row">' . esc_html( (string) ($row['site_name'] ?? '')) . '<br><small>#' . esc_html( (string) ($row['site_id'] ?? 0)) . '</small></th>';
            foreach (['message_uuid', 'status', 'provider', 'attempts', 'switchovers', 'source', 'recipients', 'attachments', 'created_at'] as $field) {
                echo '<td>' . esc_html( (string) ($row[ $field ] ?? '')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        $this->renderLogPagination($filters, $page, $perPage, $maxPage);
        echo '</section>';
    }

    /** @param array<string,mixed> $filters */
    private function renderLogFilters(array $filters, int $perPage): void
    {
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '"><input type="hidden" name="tab" value="logs"><label for="onesmtp-network-log-status">' . esc_html__('Status', 'onesmtp') . '</label> <select id="onesmtp-network-log-status" name="status"><option value="">' . esc_html__('Any status', 'onesmtp') . '</option>';
        foreach (['queued', 'retrying', 'retry_scheduled', 'sent', 'failed', 'simulated'] as $status) {
            echo '<option value="' . esc_attr($status) . '"' . ($filters['status'] === $status ? ' selected="selected"' : '') . '>' . esc_html($status) . '</option>';
        }
        echo '</select> <label for="onesmtp-network-log-site">' . esc_html__('Site ID', 'onesmtp') . '</label> <input id="onesmtp-network-log-site" type="number" min="0" name="site_id" value="' . esc_attr( (string) $filters['site_id']) . '"> <label for="onesmtp-network-log-search">' . esc_html__('Search', 'onesmtp') . '</label> <input id="onesmtp-network-log-search" type="search" maxlength="120" name="s" value="' . esc_attr($filters['search']) . '"> <label for="onesmtp-network-logs-per-page">' . esc_html__('Per page', 'onesmtp') . '</label> <select id="onesmtp-network-logs-per-page" name="network_logs_per_page">';
        foreach ([10, 25, 50] as $option) {
            echo '<option value="' . esc_attr( (string) $option) . '"' . ($perPage === $option ? ' selected="selected"' : '') . '>' . esc_html( (string) $option) . '</option>';
        }
        echo '</select> ';
		submit_button(__('Filter network logs', 'onesmtp'), 'secondary', 'submit', false);
		echo '</form>';
    }

    /** @param array<string,mixed> $filters */
    private function renderLogPagination(array $filters, int $page, int $perPage, int $maxPage): void
    {
        if ($maxPage <= 1) {
            return;
        }
        echo '<p class="tablenav-pages">';
        if ($page > 1) {
            echo '<a class="button" href="' . esc_url($this->logUrl($filters, $page - 1, $perPage)) . '">' . esc_html__('Previous', 'onesmtp') . '</a> ';
        }
        /* translators: 1: current page, 2: total pages. */
        echo '<span>' . esc_html(sprintf(__('Page %1$d of %2$d', 'onesmtp'), $page, $maxPage)) . '</span>';
        if ($page < $maxPage) {
            echo ' <a class="button" href="' . esc_url($this->logUrl($filters, $page + 1, $perPage)) . '">' . esc_html__('Next', 'onesmtp') . '</a>';
        }
        echo '</p>';
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        return [
            'status' => isset($_GET['status']) ? sanitize_key(wp_unslash( (string) $_GET['status'])) : '',
            'site_id' => isset($_GET['site_id']) ? absint(wp_unslash( (string) $_GET['site_id'])) : 0,
            'search' => isset($_GET['s']) ? substr(sanitize_text_field(wp_unslash( (string) $_GET['s'])), 0, 120) : '',
        ];
    }

    /** @param array<string,mixed> $filters */
    private function hasFilters(array $filters): bool
    {
        return $filters['status'] !== '' || $filters['site_id'] > 0 || $filters['search'] !== '';
    }

    /** @param array<string,mixed> $filters */
    private function logUrl(array $filters, int $page, int $perPage): string
    {
        return add_query_arg([
            'page' => self::MENU_SLUG,
            'tab' => 'logs',
            'network_log_page' => max(1, $page),
            'network_logs_per_page' => max(1, min(self::MAX_PER_PAGE, $perPage)),
            'status' => $filters['status'],
            'site_id' => $filters['site_id'],
            's' => $filters['search'],
        ], network_admin_url('settings.php'));
    }

    /** @return array{name:string,settings:array<string,mixed>} */
    private function readSite(int $siteId): array
    {
        $site = [
            /* translators: %d: site ID. */
            'name' => sprintf(__('Site %d', 'onesmtp'), $siteId),
			'settings' => [
				'inheritance' => [],
				'overrides' => [],
			],
		];
        if ( ! function_exists('switch_to_blog') || ! switch_to_blog($siteId)) {
            return $site;
        }
        $site['name'] = function_exists('get_bloginfo') ? sanitize_text_field( (string) get_bloginfo('name')) : $site['name'];
        $site['settings'] = $this->settings->getSite();
        if (function_exists('restore_current_blog')) {
            restore_current_blog();
        }

        return $site;
    }

    private function numberField(string $name, string $label, int $value): void
    {
        echo '<label style="display:inline-block;margin-right:1em" for="' . esc_attr($name) . '">' . esc_html($label) . ' <input id="' . esc_attr($name) . '" type="number" min="0" max="10000000" name="' . esc_attr($name) . '" value="' . esc_attr( (string) $value) . '"></label>';
    }

    private function checkbox(string $name, string $label, bool $checked): void
    {
        echo '<p><label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . ($checked ? ' checked="checked"' : '') . '> ' . esc_html($label) . '</label></p>';
    }

    private function postInt(string $key): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handleRequest verifies the network nonce before reading POST fields.
        return isset($_POST[ $key ]) ? absint(wp_unslash( (string) $_POST[ $key ])) : 0;
    }

    private function isCurrentPage(): bool
    {
        return isset($_GET['page']) && sanitize_key(wp_unslash( (string) $_GET['page'])) === self::MENU_SLUG;
    }

    private function renderNotice(): void
    {
        $status = isset($_GET['onesmtp_network_status']) ? sanitize_key(wp_unslash( (string) $_GET['onesmtp_network_status'])) : '';
        $messages = [
            'network_saved' => ['success', __('Network defaults saved.', 'onesmtp')],
            'site_saved' => ['success', __('Site override saved.', 'onesmtp')],
            'network_failed' => ['error', __('Network defaults could not be saved.', 'onesmtp')],
            'site_failed' => ['error', __('Site override could not be saved.', 'onesmtp')],
        ];
        if ( ! isset($messages[ $status ])) {
            return;
        }
        echo '<div class="notice notice-' . esc_attr($messages[ $status ][0]) . ' is-dismissible"><p>' . esc_html($messages[ $status ][1]) . '</p></div>';
    }

    private function redirect(string $status, int $siteId = 0): void
    {
        $url = network_admin_url('settings.php?page=' . self::MENU_SLUG . '&tab=settings');
        $args = ['onesmtp_network_status' => $status];
        if ($siteId > 0) {
            $args['network_site_id'] = $siteId;
        }
        wp_safe_redirect(add_query_arg($args, $url));
        if (defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING')) {
            throw new RuntimeException('Aculect Mail network admin redirected.');
        }
        exit;
    }

    private function deny(): void
    {
        wp_die(
            esc_html__('You do not have permission to access Aculect Mail network controls.', 'onesmtp'),
            esc_html__('Aculect Mail network access denied', 'onesmtp'),
            ['response' => 403]
        );
    }
}
