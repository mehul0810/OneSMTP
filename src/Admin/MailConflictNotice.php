<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Conflict\MailConflictDetectorInterface;
use OneSMTP\Core\Capabilities;

final class MailConflictNotice
{
    private const DISMISS_TRANSIENT = 'onesmtp_mail_conflict_notice_dismissed_';

    private MailConflictDetectorInterface $detector;

    public function __construct(MailConflictDetectorInterface $detector)
    {
        $this->detector = $detector;
    }

    public function registerHooks(): void
    {
        add_action('admin_notices', [$this, 'render']);
        add_action('admin_post_onesmtp_dismiss_mail_conflict_notice', [$this, 'dismiss']);
    }

    public function render(): void
    {
        if (! Capabilities::canManage() || $this->isDismissed()) {
            return;
        }

        $conflicts = $this->detector->detect();
        if ($conflicts['plugins'] === [] && $conflicts['hooks'] === []) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible onesmtp-mail-conflict-notice">';
        echo '<p><strong>' . esc_html__('OneSMTP found other mail delivery logic on this site.', 'onesmtp') . '</strong></p>';
        echo '<p>' . esc_html__('This can cause duplicate sends, unexpected transport changes, or confusing delivery results. OneSMTP will not disable or reorder third-party code automatically.', 'onesmtp') . '</p>';

        if ($conflicts['plugins'] !== []) {
            echo '<p>' . esc_html__('Detected active mail plugins:', 'onesmtp') . ' ';
            echo esc_html(implode(', ', array_slice($conflicts['plugins'], 0, 8)));
            if (count($conflicts['plugins']) > 8) {
                echo esc_html__(' and more', 'onesmtp');
            }
            echo '</p>';
        }

        if ($conflicts['hooks'] !== []) {
            echo '<p>' . esc_html__('Detected mail-related hooks:', 'onesmtp') . ' ';
            echo esc_html($this->formatHooks($conflicts['hooks']));
            echo '</p>';
        }

        echo '<p>' . esc_html__('Recommended next step: review active mail plugins and custom mail hooks, then keep only the delivery path you intend to manage.', 'onesmtp') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="onesmtp_dismiss_mail_conflict_notice">';
        wp_nonce_field('onesmtp_dismiss_mail_conflict_notice');
        submit_button(__('Remind me later', 'onesmtp'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';
    }

    public function dismiss(): void
    {
        if (! Capabilities::canManage()) {
            wp_die(esc_html__('You do not have permission to dismiss OneSMTP notices.', 'onesmtp'));
        }

        check_admin_referer('onesmtp_dismiss_mail_conflict_notice');
        set_transient($this->dismissTransientKey(), 1, DAY_IN_SECONDS * 7);
        wp_safe_redirect(admin_url('admin.php?page=onesmtp'));
        exit;
    }

    /**
     * @param array<string,int> $hooks
     */
    private function formatHooks(array $hooks): string
    {
        $parts = [];
        foreach ($hooks as $hook => $count) {
            $parts[] = sprintf('%s (%d)', $hook, $count);
        }

        return implode(', ', $parts);
    }

    private function isDismissed(): bool
    {
        return get_transient($this->dismissTransientKey()) !== false;
    }

    private function dismissTransientKey(): string
    {
        return self::DISMISS_TRANSIENT . get_current_user_id();
    }
}
