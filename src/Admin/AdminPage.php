<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Repository\ProviderRepository;

final class AdminPage
{
    private const MENU_SLUG = 'onesmtp';

    private ProviderAdmin $providers;

    public function __construct(?ProviderAdmin $providers = null)
    {
        $this->providers = $providers ?? new ProviderAdmin(new ProviderRepository());
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this->providers, 'handleRequest']);
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
        echo '<h1>' . esc_html__('OneSMTP', 'onesmtp') . '</h1>';
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('OneSMTP sections', 'onesmtp') . '">';

        foreach ($sections as $section) {
            echo '<a class="nav-tab" href="' . esc_url($section['href']) . '">' . esc_html($section['title']) . '</a>';
        }

        echo '</nav>';
        echo '<div class="onesmtp-admin-shell">';

        foreach ($sections as $section) {
            echo '<section id="' . esc_attr($section['id']) . '" class="onesmtp-admin-section">';
            echo '<h2>' . esc_html($section['title']) . '</h2>';

            if ($section['id'] === 'onesmtp-providers') {
                $this->providers->render();
            } else {
                echo '<p>' . esc_html($section['description']) . '</p>';
            }

            echo '</section>';
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
                'description' => esc_html__('Operational notices and delivery diagnostics will appear here as diagnostics are wired into the admin UI.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-diagnostics',
            ],
            [
                'id' => 'onesmtp-settings',
                'title' => esc_html__('Settings', 'onesmtp'),
                'description' => esc_html__('Plugin settings will appear here once the settings form is ready.', 'onesmtp'),
                'href' => $baseUrl . '#onesmtp-settings',
            ],
        ];
    }
}
