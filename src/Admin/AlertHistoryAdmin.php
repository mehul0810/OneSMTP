<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Alerts\AlertEventRepository;
use OneSMTP\Core\Capabilities;

final class AlertHistoryAdmin
{
    private const ACTION_NAME = 'onesmtp_alert_history_action';
    private const NONCE_NAME = 'onesmtp_alert_history_nonce';
    private const EVENT_ID_NAME = 'onesmtp_alert_event_id';
    private const ACKNOWLEDGE_ACTION = 'acknowledge';

    public function __construct(private ?AlertEventRepository $alerts = null)
    {
        $this->alerts = $alerts ?? new AlertEventRepository();
    }

    public function handleRequest(): void
    {
        if ( (string) ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $action = isset($_POST[ self::ACTION_NAME ]) ? sanitize_key(wp_unslash( (string) $_POST[ self::ACTION_NAME ])) : '';
        if ($action !== self::ACKNOWLEDGE_ACTION) {
            return;
        }

        if ( ! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to acknowledge OneSMTP alerts.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $eventId = isset($_POST[ self::EVENT_ID_NAME ]) ? absint(wp_unslash($_POST[ self::EVENT_ID_NAME ])) : 0;
        $nonce = isset($_POST[ self::NONCE_NAME ]) ? sanitize_text_field(wp_unslash( (string) $_POST[ self::NONCE_NAME ])) : '';
        if ($eventId <= 0 || $nonce === '' || ! wp_verify_nonce($nonce, $this->nonceAction($eventId))) {
            wp_die(
                esc_html__('The OneSMTP alert acknowledgement link has expired. Refresh the page and try again.', 'onesmtp'),
                esc_html__('OneSMTP alert acknowledgement denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $acknowledgementId = $this->alerts->acknowledge($eventId);
        $this->redirect($acknowledgementId > 0 ? 'acknowledged' : 'failed');
    }

    public function render(): void
    {
        echo '<p>' . esc_html__('Review privacy-safe alert events and record administrator acknowledgement for operational follow-up. Raw recipients, message bodies, full headers, provider secrets, tokens, credentials, and private provider responses are excluded.', 'onesmtp') . '</p>';

        $this->renderStatusNotice();

        $events = $this->alerts->recent(20);
        if ($events === []) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('No alert events have been recorded yet.', 'onesmtp') . '</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Event', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Status', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Summary', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Context', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Acknowledgement', 'onesmtp') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($events as $event) {
            $this->renderEventRow($event);
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $event
     */
    private function renderEventRow(array $event): void
    {
        $eventId = (int) ($event['id'] ?? 0);
        $status = sanitize_key( (string) ($event['status'] ?? 'open'));

        echo '<tr>';
        echo '<th scope="row">';
        echo '<strong>#' . esc_html( (string) $eventId) . '</strong><br>';
        echo esc_html(str_replace('_', ' ', (string) ($event['event_type'] ?? '')));
        echo '<br><small>' . esc_html( (string) ($event['created_at'] ?? '')) . '</small>';
        echo '</th>';
        echo '<td>' . esc_html($status === 'acknowledged' ? __('Acknowledged', 'onesmtp') : __('Open', 'onesmtp')) . '</td>';
        echo '<td>' . esc_html( (string) ($event['summary'] ?? '')) . '</td>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- contextPreview() returns escaped readonly markup.
        echo '<td>' . $this->contextPreview( (array) ($event['context'] ?? []), $eventId) . '</td>';
        echo '<td>';

        if ($status === 'acknowledged') {
            printf(
                '%s<br><small>%s #%d</small>',
                esc_html( (string) ($event['acknowledged_at'] ?? '')),
                esc_html__('Actor', 'onesmtp'),
                (int) ($event['acknowledged_by'] ?? 0)
            );
        } else {
            $this->renderAcknowledgeForm($eventId);
        }

        echo '</td>';
        echo '</tr>';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function contextPreview(array $context, int $eventId): string
    {
        $encoded = wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ( ! is_string($encoded) || $encoded === '') {
            return esc_html__('No additional context.', 'onesmtp');
        }

        $fieldId = 'onesmtp-alert-context-' . $eventId;

        return '<label class="screen-reader-text" for="' . esc_attr($fieldId) . '">' . esc_html__('Alert context', 'onesmtp') . '</label>'
            . '<textarea id="' . esc_attr($fieldId) . '" class="large-text code" rows="5" readonly="readonly">' . esc_textarea($encoded) . '</textarea>';
    }

    private function renderAcknowledgeForm(int $eventId): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=onesmtp#onesmtp-alerts')) . '">';
        echo '<input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="' . esc_attr(self::ACKNOWLEDGE_ACTION) . '">';
        echo '<input type="hidden" name="' . esc_attr(self::EVENT_ID_NAME) . '" value="' . esc_attr( (string) $eventId) . '">';
        wp_nonce_field($this->nonceAction($eventId), self::NONCE_NAME);
        submit_button(__('Acknowledge', 'onesmtp'), 'secondary small', 'submit', false);
        echo '</form>';
    }

    private function renderStatusNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin notice state after a nonce-protected action redirect.
        $status = isset($_GET['onesmtp_alert_history_status']) ? sanitize_key(wp_unslash( (string) $_GET['onesmtp_alert_history_status'])) : '';
        if ($status === '') {
            return;
        }

        if ($status === 'acknowledged') {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Alert event acknowledged.', 'onesmtp') . '</p></div>';
            return;
        }

        echo '<div class="notice notice-error inline"><p>' . esc_html__('OneSMTP could not acknowledge that alert event. Refresh the page and try again.', 'onesmtp') . '</p></div>';
    }

    private function redirect(string $status): void
    {
        wp_safe_redirect(
            add_query_arg(
                ['onesmtp_alert_history_status' => $status],
                admin_url('admin.php?page=onesmtp#onesmtp-alerts')
            )
        );

        if ($this->isTestingRuntime()) {
            throw new \RuntimeException('OneSMTP alert history redirected.');
        }

        exit;
    }

    private function nonceAction(int $eventId): string
    {
        return 'onesmtp_acknowledge_alert_event_' . $eventId;
    }

    private function isTestingRuntime(): bool
    {
        return defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING');
    }
}
