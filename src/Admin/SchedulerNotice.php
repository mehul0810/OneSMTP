<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;

final class SchedulerNotice
{
    private ActionSchedulerHealth $health;
    private ProviderRepository $providers;
    private QueueDiagnostics $diagnostics;

    public function __construct(ActionSchedulerHealth $health, ?ProviderRepository $providers = null, ?QueueDiagnostics $diagnostics = null)
    {
        $this->health = $health;
        $this->providers = $providers ?? new ProviderRepository();
        $this->diagnostics = $diagnostics ?? new QueueDiagnostics($health, new MessageRepository());
    }

    public function registerHooks(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (! Capabilities::canManage()) {
            return;
        }

        if ($this->providers->getActiveProviders() === []) {
            $this->renderLinkedNotice(
                'notice-error',
                __(
                    'OneSMTP has no active email provider. Outbound email cannot be delivered until at least one provider is configured and active.',
                    'onesmtp'
                ),
                admin_url('options-general.php?page=onesmtp#onesmtp-providers'),
                __('Configure providers', 'onesmtp')
            );
        }

        if (! $this->health->isAvailable()) {
            $this->renderLinkedNotice(
                'notice-error',
                __(
                    'OneSMTP retry scheduling is unavailable because Action Scheduler is not loaded. Failed messages will not be queued for background retry until the scheduler is available.',
                    'onesmtp'
                ),
                admin_url('options-general.php?page=onesmtp#onesmtp-diagnostics'),
                __('Review diagnostics', 'onesmtp')
            );

            return;
        }

        $snapshot = $this->diagnostics->snapshot();
        if ((int) $snapshot['overdue_retry_count'] <= 0) {
            return;
        }

        $this->renderLinkedNotice(
            'notice-warning',
            __(
                'OneSMTP has overdue retry jobs, so queue processing may be blocked. Review diagnostics before resending or changing provider settings.',
                'onesmtp'
            ),
            admin_url('options-general.php?page=onesmtp#onesmtp-diagnostics'),
            __('Review queue diagnostics', 'onesmtp')
        );
    }

    private function renderLinkedNotice(string $type, string $message, string $url, string $linkText): void
    {
        echo '<div class="notice ' . esc_attr($type) . '"><p>';
        echo esc_html($message);
        echo ' <a href="' . esc_url($url) . '">' . esc_html($linkText) . '</a>';
        echo '</p></div>';
    }
}
