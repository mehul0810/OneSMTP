<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Diagnostics\DiagnosticReportGenerator;
use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;

final class QueueDiagnosticsAdmin
{
    private const ACTION_NAME = 'onesmtp_diagnostic_action';
    private const NONCE_NAME  = 'onesmtp_diagnostic_nonce';
    private const DOWNLOAD_ACTION = 'download_report';

    private QueueDiagnostics $diagnostics;
    private DiagnosticReportGenerator $reportGenerator;

    public function __construct(?QueueDiagnostics $diagnostics = null, ?DiagnosticReportGenerator $reportGenerator = null)
    {
        $this->diagnostics = $diagnostics ?? new QueueDiagnostics(new ActionSchedulerHealth(), new MessageRepository());
        $this->reportGenerator = $reportGenerator ?? new DiagnosticReportGenerator(new ProviderRepository(), $this->diagnostics, new AttemptRepository());
    }

    public function handleRequest(): void
    {
        if ((string) ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            return;
        }

        $action = isset($_GET[self::ACTION_NAME]) ? sanitize_key(wp_unslash((string) $_GET[self::ACTION_NAME])) : '';
        if ($action !== self::DOWNLOAD_ACTION) {
            return;
        }

        if (! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to download OneSMTP diagnostics.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $nonce = isset($_GET[self::NONCE_NAME]) ? sanitize_text_field(wp_unslash((string) $_GET[self::NONCE_NAME])) : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, self::DOWNLOAD_ACTION)) {
            wp_die(
                esc_html__('The OneSMTP diagnostic report link has expired. Refresh the page and try again.', 'onesmtp'),
                esc_html__('OneSMTP diagnostics denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $report = $this->reportGenerator->generate();

        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename=onesmtp-diagnostic-report.json');
            header('X-Content-Type-Options: nosniff');
        }

        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($this->isTestingRuntime()) {
            throw new \RuntimeException('OneSMTP diagnostic report downloaded.');
        }

        exit;
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

        $this->renderReportSection();
    }

    private function renderRow(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function formatStatus(string $status): string
    {
        return str_replace('_', ' ', sanitize_key($status));
    }

    private function renderReportSection(): void
    {
        echo '<h3>' . esc_html__('Privacy-safe diagnostic report', 'onesmtp') . '</h3>';
        echo '<p>' . esc_html__('Generate a support report with environment metadata, plugin status, provider summaries, queue state, recent failure categories, and redaction metadata. Raw recipients, message bodies, headers, payload JSON, webhook URLs, tokens, credentials, and provider configuration values are excluded.', 'onesmtp') . '</p>';

        $downloadUrl = add_query_arg(
            [
                'page' => 'onesmtp',
                self::ACTION_NAME => self::DOWNLOAD_ACTION,
                self::NONCE_NAME => wp_create_nonce(self::DOWNLOAD_ACTION),
            ],
            admin_url('admin.php#onesmtp-diagnostics')
        );

        echo '<p><a class="button button-secondary" href="' . esc_url($downloadUrl) . '">' . esc_html__('Download diagnostic report', 'onesmtp') . '</a></p>';

        try {
            $report = $this->reportGenerator->generate();
        } catch (\Throwable $e) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('OneSMTP could not generate the diagnostic report preview. Refresh the page and try again.', 'onesmtp') . '</p></div>';

            return;
        }

        $encoded = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('The diagnostic report preview is empty.', 'onesmtp') . '</p></div>';

            return;
        }

        echo '<p><label for="onesmtp-diagnostic-preview">' . esc_html__('Report preview', 'onesmtp') . '</label></p>';
        echo '<textarea id="onesmtp-diagnostic-preview" class="large-text code" rows="18" readonly="readonly">' . esc_textarea($encoded) . '</textarea>';
    }

    private function isTestingRuntime(): bool
    {
        return defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING');
    }
}
