<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\Redactor;

final class LogAdmin
{
    private const DETAIL_PARAM = 'onesmtp_message_id';
    private const ERROR_LIMIT = 220;

    private MessageRepository $messages;
    private AttemptRepository $attempts;
    private ProviderRepository $providers;
    private Redactor $redactor;

    public function __construct(
        MessageRepository $messages,
        AttemptRepository $attempts,
        ProviderRepository $providers,
        ?Redactor $redactor = null
    ) {
        $this->messages = $messages;
        $this->attempts = $attempts;
        $this->providers = $providers;
        $this->redactor = $redactor ?? new Redactor();
    }

    public function render(): void
    {
        if (! Capabilities::canViewLogs()) {
            wp_die(
                esc_html__('You do not have permission to view OneSMTP logs.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        echo '<p>' . esc_html__('Review delivery lineage without exposing message bodies, raw headers, secrets, or full recipient addresses.', 'onesmtp') . '</p>';

        $messageId = isset($_GET[self::DETAIL_PARAM]) ? absint(wp_unslash((string) $_GET[self::DETAIL_PARAM])) : 0;
        if ($messageId > 0) {
            $this->renderDetail($messageId);
            echo '<hr>';
        }

        $this->renderList();
    }

    private function renderList(): void
    {
        $messages = $this->messages->listRecentWithAttemptCounts(50);

        echo '<h3>' . esc_html__('Recent messages', 'onesmtp') . '</h3>';

        if ($messages === []) {
            echo '<p>' . esc_html__('No email log entries have been recorded yet.', 'onesmtp') . '</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Message', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Status', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Provider', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Attempts', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Recipients', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Created', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Updated', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($messages as $message) {
            $messageId = (int) ($message['id'] ?? 0);
            $detailUrl = add_query_arg(
                [self::DETAIL_PARAM => $messageId],
                admin_url('admin.php?page=onesmtp#onesmtp-logs')
            );

            echo '<tr>';
            echo '<td><a href="' . esc_url($detailUrl) . '">#' . esc_html((string) $messageId) . '</a><br><code>' . esc_html((string) ($message['message_uuid'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html($this->formatStatus((string) ($message['status'] ?? ''))) . '</td>';
            echo '<td>' . esc_html($this->formatProvider((int) ($message['selected_provider_id'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($message['attempt_count'] ?? $message['current_attempt'] ?? 0))) . ' / ' . esc_html((string) ((int) ($message['max_attempts'] ?? 0))) . '</td>';
            echo '<td>' . esc_html($this->formatRecipientSummary($this->payloadFor($message))) . '</td>';
            echo '<td>' . esc_html((string) ($message['created_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($message['updated_at'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function renderDetail(int $messageId): void
    {
        $message = $this->messages->find($messageId);
        if (! is_array($message)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('The requested log entry was not found.', 'onesmtp') . '</p></div>';

            return;
        }

        $payload = $this->payloadFor($message);
        $attempts = $this->attempts->listByMessageId($messageId);

        echo '<h3>' . esc_html__('Message detail', 'onesmtp') . '</h3>';
        echo '<table class="widefat striped"><tbody>';
        $this->renderDetailRow(__('Message ID', 'onesmtp'), '#' . (string) $messageId);
        $this->renderDetailRow(__('Lineage UUID', 'onesmtp'), (string) ($message['message_uuid'] ?? ''));
        $this->renderDetailRow(__('Status', 'onesmtp'), $this->formatStatus((string) ($message['status'] ?? '')));
        $this->renderDetailRow(__('Selected provider', 'onesmtp'), $this->formatProvider((int) ($message['selected_provider_id'] ?? 0)));
        $this->renderDetailRow(__('Attempts', 'onesmtp'), (string) count($attempts) . ' / ' . (string) ((int) ($message['max_attempts'] ?? 0)));
        $this->renderDetailRow(__('Recipients', 'onesmtp'), $this->formatRecipientSummary($payload));
        $this->renderDetailRow(__('Next retry', 'onesmtp'), (string) ($message['next_retry_at'] ?? __('None scheduled', 'onesmtp')));
        $this->renderDetailRow(__('Created', 'onesmtp'), (string) ($message['created_at'] ?? ''));
        $this->renderDetailRow(__('Updated', 'onesmtp'), (string) ($message['updated_at'] ?? ''));
        echo '</tbody></table>';

        echo '<h4>' . esc_html__('Attempt lineage', 'onesmtp') . '</h4>';
        if ($attempts === []) {
            echo '<p>' . esc_html__('No delivery attempts have been recorded for this message yet.', 'onesmtp') . '</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Attempt', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Provider', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Trigger', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Result', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Safe error context', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Provider message', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Latency', 'onesmtp') . '</th>';
        echo '<th>' . esc_html__('Created', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($attempts as $attempt) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ((int) ($attempt['attempt_no'] ?? 0))) . '</td>';
            echo '<td>' . esc_html($this->formatProvider((int) ($attempt['provider_id'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ($attempt['trigger_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html($this->formatStatus((string) ($attempt['result'] ?? ''))) . '</td>';
            echo '<td style="max-width:32em;white-space:normal;word-break:break-word;">' . esc_html($this->formatError($attempt)) . '</td>';
            echo '<td><code>' . esc_html($this->shortCode((string) ($attempt['provider_message_id'] ?? ''))) . '</code></td>';
            echo '<td>' . esc_html(isset($attempt['latency_ms']) ? (string) ((int) $attempt['latency_ms']) . 'ms' : '') . '</td>';
            echo '<td>' . esc_html((string) ($attempt['created_at'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function renderDetailRow(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function payloadFor(array $message): array
    {
        $payload = isset($message['payload_json']) ? json_decode((string) $message['payload_json'], true) : [];

        return is_array($payload) ? $payload : [];
    }

    private function formatRecipientSummary(array $payload): string
    {
        $recipients = $this->normalizeRecipients($payload['to'] ?? []);
        if ($recipients === []) {
            return __('0 recipients', 'onesmtp');
        }

        $domains = [];
        foreach ($recipients as $recipient) {
            $domain = strtolower((string) substr(strrchr($recipient, '@') ?: '', 1));
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        $domainList = array_keys($domains);
        sort($domainList);
        $visibleDomains = array_slice($domainList, 0, 3);
        $suffix = count($domainList) > 3 ? ', +' . (string) (count($domainList) - 3) . ' more' : '';

        return sprintf(
            /* translators: 1: recipient count, 2: domain list. */
            __('%1$d recipients across %2$s', 'onesmtp'),
            count($recipients),
            $visibleDomains !== [] ? implode(', ', $visibleDomains) . $suffix : __('unknown domains', 'onesmtp')
        );
    }

    private function normalizeRecipients(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,;]/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $recipients = [];
        foreach ($raw as $value) {
            $email = is_string($value) ? trim($value) : '';
            if ($email !== '' && str_contains($email, '@')) {
                $recipients[] = $email;
            }
        }

        return array_values(array_unique($recipients));
    }

    private function formatProvider(int $providerId): string
    {
        if ($providerId <= 0) {
            return __('Not selected', 'onesmtp');
        }

        $provider = $this->providers->findSafe($providerId);
        if (! is_array($provider)) {
            return sprintf(
                /* translators: %d: provider id. */
                __('Provider #%d', 'onesmtp'),
                $providerId
            );
        }

        $name = trim((string) ($provider['name'] ?? ''));
        $type = trim((string) ($provider['adapter_type'] ?? ''));

        return trim($name . ($type !== '' ? ' (' . $type . ')' : ''));
    }

    private function formatStatus(string $status): string
    {
        $status = sanitize_key($status);

        return $status !== '' ? str_replace('_', ' ', $status) : __('Unknown', 'onesmtp');
    }

    private function formatError(array $attempt): string
    {
        $code = trim((string) ($attempt['error_code'] ?? ''));
        $message = trim((string) ($attempt['error_message'] ?? ''));
        if ($code === '' && $message === '') {
            return __('None', 'onesmtp');
        }

        $context = trim($code . ($message !== '' ? ': ' . $message : ''));

        return $this->redactor->redactText($context, self::ERROR_LIMIT);
    }

    private function shortCode(string $value): string
    {
        $value = trim($this->redactor->redactText($value, 80));

        return $value !== '' ? $value : __('Unavailable', 'onesmtp');
    }
}
