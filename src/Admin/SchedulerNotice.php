<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Queue\ActionSchedulerHealth;

final class SchedulerNotice
{
    private ActionSchedulerHealth $health;

    public function __construct(ActionSchedulerHealth $health)
    {
        $this->health = $health;
    }

    public function registerHooks(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (! Capabilities::canManage() || $this->health->isAvailable()) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__(
            'OneSMTP retry scheduling is unavailable because Action Scheduler is not loaded. Failed messages will not be queued for background retry until the scheduler is available.',
            'onesmtp'
        );
        echo '</p></div>';
    }
}
