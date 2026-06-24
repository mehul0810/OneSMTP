<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Repository\MessageRepository;

final class QueueDiagnosticsAdmin
{
    private QueueDiagnostics $diagnostics;

    public function __construct(?QueueDiagnostics $diagnostics = null)
    {
        $this->diagnostics = $diagnostics ?? new QueueDiagnostics(new ActionSchedulerHealth(), new MessageRepository());
    }

    public function render(): void
    {
        $snapshot = $this->diagnostics->snapshot();
        $status = (string) $snapshot['queue_status'];

        echo '<p>' . esc_html__('Review aggregate queue health without exposing recipients, message bodies, provider credentials, tokens, or raw payload content.', 'onesmtp') . '</p>';

        if ($status === 'attention') {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('OneSMTP detected queue conditions that need administrator review.', 'onesmtp') . '</p></div>';
        } elseif ($status === 'empty') {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('OneSMTP has no queued or retrying messages.', 'onesmtp') . '</p></div>';
        } else {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('OneSMTP retry processing is available and queue activity is within expected bounds.', 'onesmtp') . '</p></div>';
        }

        echo '<table class="widefat striped">';
        echo '<tbody>';
        $this->renderRow(__('Scheduler availability', 'onesmtp'), (bool) $snapshot['scheduler_available'] ? __('Available', 'onesmtp') : __('Unavailable', 'onesmtp'));
        $this->renderRow(__('Queue status', 'onesmtp'), $this->formatStatus($status));
        $this->renderRow(__('Queued messages', 'onesmtp'), (string) ((int) $snapshot['queued_count']));
        $this->renderRow(__('Scheduled retries', 'onesmtp'), (string) ((int) $snapshot['retry_scheduled_count']));
        $this->renderRow(__('Overdue retries', 'onesmtp'), (string) ((int) $snapshot['overdue_retry_count']));
        $this->renderRow(__('Running retries', 'onesmtp'), (string) ((int) $snapshot['retrying_count']));
        $this->renderRow(__('Failed messages', 'onesmtp'), (string) ((int) $snapshot['failed_count']));
        $this->renderRow(__('Next retry', 'onesmtp'), (string) ($snapshot['next_retry_at'] ?? __('None scheduled', 'onesmtp')));
        echo '</tbody>';
        echo '</table>';

        echo '<h3>' . esc_html__('Recommended recovery actions', 'onesmtp') . '</h3>';
        echo '<ul>';
        foreach ((array) $snapshot['recommended_actions'] as $action) {
            echo '<li>' . esc_html((string) $action) . '</li>';
        }
        echo '</ul>';
    }

    private function renderRow(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function formatStatus(string $status): string
    {
        return str_replace('_', ' ', sanitize_key($status));
    }
}
