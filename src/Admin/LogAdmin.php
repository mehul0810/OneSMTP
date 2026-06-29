<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Logging\AttachmentLogSanitizer;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\Redactor;

final class LogAdmin
{
    private const DETAIL_PARAM = 'onesmtp_message_id';
    private const ACTION_NAME  = 'onesmtp_log_action';
    private const NONCE_NAME   = 'onesmtp_log_nonce';
    private const EXPORT_ACTION = 'export_csv';
    private const EXPORT_NONCE_NAME = 'onesmtp_log_export_nonce';
    private const BULK_RESEND_ACTION = 'bulk_resend';
    private const FORWARD_ACTION = 'forward_summary';
    private const BULK_MESSAGE_IDS_PARAM = 'onesmtp_message_ids';
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;
    private const MAX_BULK_MESSAGES = 50;
    private const EXPORT_LIMIT = 1000;
    private const ERROR_LIMIT = 220;

    private MessageRepository $messages;
    private AttemptRepository $attempts;
    private ProviderRepository $providers;
    private Redactor $redactor;
    /** @var callable(int,?int):bool|null */
    private $resendHandler;

    public function __construct(
        MessageRepository $messages,
        AttemptRepository $attempts,
        ProviderRepository $providers,
        ?Redactor $redactor = null,
        ?callable $resendHandler = null
    ) {
        $this->messages = $messages;
        $this->attempts = $attempts;
        $this->providers = $providers;
        $this->redactor = $redactor ?? new Redactor();
        $this->resendHandler = $resendHandler;
    }

    public function handleRequest(): void
    {
        $requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? '');

        if ($requestMethod === 'GET') {
            $action = isset($_GET[self::ACTION_NAME]) ? sanitize_key(wp_unslash((string) $_GET[self::ACTION_NAME])) : '';
            if ($action === self::EXPORT_ACTION) {
                $this->handleCsvExport();
            }

            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        $action = isset($_POST[self::ACTION_NAME]) ? sanitize_key(wp_unslash((string) $_POST[self::ACTION_NAME])) : '';
        if ($action === 'resend') {
            $this->handleResend();

            return;
        }

        if ($action === self::BULK_RESEND_ACTION) {
            $this->handleBulkResend();

            return;
        }

        if ($action === self::FORWARD_ACTION) {
            $this->handleForward();
        }
    }

    private function handleResend(): void
    {
        if (! Capabilities::canResendEmails()) {
            wp_die(
                esc_html__('You do not have permission to resend OneSMTP emails.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION_NAME, self::NONCE_NAME);

        $messageId = isset($_POST[self::DETAIL_PARAM]) ? absint(wp_unslash((string) $_POST[self::DETAIL_PARAM])) : 0;
        if ($messageId <= 0 || ! is_array($this->messages->find($messageId))) {
            $this->redirect('missing', $messageId);
        }

        $providerId = isset($_POST['provider_id']) ? absint(wp_unslash((string) $_POST['provider_id'])) : 0;
        if ($providerId > 0 && ! $this->isEligibleProvider($providerId)) {
            $this->redirect('ineligible_provider', $messageId);
        }

        $handler = $this->resendHandler;
        $sent = is_callable($handler) && (bool) $handler($messageId, $providerId > 0 ? $providerId : null);

        $this->redirect($sent ? 'resent' : 'failed', $messageId);
    }

    private function handleBulkResend(): void
    {
        if (! Capabilities::canResendEmails()) {
            wp_die(
                esc_html__('You do not have permission to bulk resend OneSMTP emails.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION_NAME, self::NONCE_NAME);

        $messageIds = $this->postedMessageIds();
        if ($messageIds === []) {
            $this->redirectBulk('empty', 0, 0);
        }

        $handler = $this->resendHandler;
        $resent = 0;
        $failed = 0;

        foreach ($messageIds as $messageId) {
            $message = $this->messages->find($messageId);
            if (! is_array($message) || (string) ($message['status'] ?? '') !== 'failed') {
                $failed++;
                continue;
            }

            $sent = is_callable($handler) && (bool) $handler($messageId, null);
            if ($sent) {
                $resent++;
            } else {
                $failed++;
            }
        }

        if ($resent > 0 && $failed === 0) {
            $this->redirectBulk('resent', $resent, $failed);
        }

        $this->redirectBulk($resent > 0 ? 'partial' : 'failed', $resent, $failed);
    }

    private function handleForward(): void
    {
        if (! Capabilities::canResendEmails()) {
            wp_die(
                esc_html__('You do not have permission to forward OneSMTP log summaries.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION_NAME, self::NONCE_NAME);

        $messageId = isset($_POST[self::DETAIL_PARAM]) ? absint(wp_unslash((string) $_POST[self::DETAIL_PARAM])) : 0;
        $message = $messageId > 0 ? $this->messages->find($messageId) : null;
        if (! is_array($message)) {
            $this->redirectForward('missing', $messageId);
        }

        $to = $this->safeForwardAddress();
        if ($to === '') {
            $this->redirectForward('unsafe_recipient', $messageId);
        }

        $sent = function_exists('wp_mail') && (bool) wp_mail(
            $to,
            sprintf(
                /* translators: %d: message log id. */
                __('OneSMTP safe log summary #%d', 'onesmtp'),
                $messageId
            ),
            $this->safeForwardBody($message),
            ['Content-Type: text/plain; charset=UTF-8']
        );

        $this->redirectForward($sent ? 'forwarded' : 'failed', $messageId);
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
        $this->renderActionNotices();

        $messageId = isset($_GET[self::DETAIL_PARAM]) ? absint(wp_unslash((string) $_GET[self::DETAIL_PARAM])) : 0;
        if ($messageId > 0) {
            $this->renderDetail($messageId);
            echo '<hr>';
        }

        $this->renderList();
    }

    private function renderList(): void
    {
        $filters = $this->filtersFromRequest();
        $page = isset($_GET['onesmtp_log_page']) ? max(1, absint(wp_unslash((string) $_GET['onesmtp_log_page']))) : 1;
        $perPage = isset($_GET['onesmtp_logs_per_page']) ? absint(wp_unslash((string) $_GET['onesmtp_logs_per_page'])) : self::DEFAULT_PER_PAGE;
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $total = $this->messages->countFiltered($filters);
        $maxPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $maxPage);
        $messages = $this->messages->listFilteredWithAttemptCounts($filters, $page, $perPage);

        echo '<h3>' . esc_html__('Recent messages', 'onesmtp') . '</h3>';
        $this->renderFilters($filters, $perPage);

        if ($messages === []) {
            echo '<p>' . esc_html(
                $this->hasActiveFilters($filters)
                    ? __('No email log entries matched the current filters.', 'onesmtp')
                    : __('No email log entries have been recorded yet.', 'onesmtp')
            ) . '</p>';

            return;
        }

        echo '<p>' . esc_html(
            sprintf(
                /* translators: 1: current page, 2: total pages, 3: total log count. */
                __('Page %1$d of %2$d (%3$d log entries)', 'onesmtp'),
                $page,
                $maxPage,
                $total
            )
        ) . '</p>';

        $canBulkResend = Capabilities::canResendEmails();
        if ($canBulkResend) {
            echo '<form method="post" class="onesmtp-bulk-resend-form">';
            wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
            echo '<p>';
            echo '<label for="onesmtp-bulk-action">' . esc_html__('Bulk action', 'onesmtp') . '</label> ';
            echo '<select id="onesmtp-bulk-action" name="' . esc_attr(self::ACTION_NAME) . '">';
            echo '<option value="">' . esc_html__('Select action', 'onesmtp') . '</option>';
            echo '<option value="' . esc_attr(self::BULK_RESEND_ACTION) . '">' . esc_html__('Resend selected failed messages', 'onesmtp') . '</option>';
            echo '</select> ';
            submit_button(__('Apply', 'onesmtp'), 'secondary', 'submit', false);
            echo '</p>';
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        if ($canBulkResend) {
            echo '<th scope="col">' . esc_html__('Select', 'onesmtp') . '</th>';
        }
        echo '<th scope="col">' . esc_html__('Message', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Status', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Provider', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Source', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Attempts', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Attachments', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Recipients', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Created', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Updated', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($messages as $message) {
            $messageId = (int) ($message['id'] ?? 0);
            $detailUrl = add_query_arg(
                [self::DETAIL_PARAM => $messageId],
                admin_url('admin.php?page=onesmtp#onesmtp-logs')
            );

            echo '<tr>';
            if ($canBulkResend) {
                echo '<td>';
                if ((string) ($message['status'] ?? '') === 'failed') {
                    echo '<input type="checkbox" name="' . esc_attr(self::BULK_MESSAGE_IDS_PARAM) . '[]" value="' . esc_attr((string) $messageId) . '" aria-label="' . esc_attr(sprintf(
                        /* translators: %d: message log id. */
                        __('Select failed log entry #%d for resend', 'onesmtp'),
                        $messageId
                    )) . '">';
                } else {
                    echo '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__('Only failed messages can be selected for bulk resend.', 'onesmtp') . '</span>';
                }
                echo '</td>';
            }
            echo '<th scope="row"><a href="' . esc_url($detailUrl) . '" aria-label="' . esc_attr(sprintf(
                /* translators: %d: message log id. */
                __('View log entry #%d details', 'onesmtp'),
                $messageId
            )) . '">#' . esc_html((string) $messageId) . '</a><br><code>' . esc_html((string) ($message['message_uuid'] ?? '')) . '</code></th>';
            $payload = $this->payloadFor($message);
            echo '<td>' . esc_html($this->formatStatus((string) ($message['status'] ?? ''))) . '</td>';
            echo '<td>' . esc_html($this->formatProvider((int) ($message['selected_provider_id'] ?? 0))) . '</td>';
            echo '<td>' . esc_html($this->formatSourceAttribution($payload)) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($message['attempt_count'] ?? $message['current_attempt'] ?? 0))) . ' / ' . esc_html((string) ((int) ($message['max_attempts'] ?? 0))) . '</td>';
            echo '<td>' . esc_html($this->formatAttachmentSummary($payload)) . '</td>';
            echo '<td>' . esc_html($this->formatRecipientSummary($payload)) . '</td>';
            echo '<td>' . esc_html((string) ($message['created_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($message['updated_at'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        if ($canBulkResend) {
            echo '</form>';
        }
        $this->renderPagination($filters, $page, $perPage, $total);
    }

    /**
     * @param array{status:string,provider_id:int,date_from:string,date_to:string,recipient_hash:string,search:string} $filters
     */
    private function renderFilters(array $filters, int $perPage): void
    {
        $providers = $this->providers->getAllSafe();

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="onesmtp-log-filters">';
        echo '<input type="hidden" name="page" value="onesmtp">';
        echo '<input type="hidden" name="onesmtp_log_page" value="1">';

        echo '<p>';
        echo '<label for="onesmtp-log-status">' . esc_html__('Status', 'onesmtp') . '</label> ';
        echo '<select id="onesmtp-log-status" name="status">';
        $this->renderOption('', __('Any status', 'onesmtp'), $filters['status']);
        foreach (['queued', 'retrying', 'retry_scheduled', 'sent', 'failed'] as $status) {
            $this->renderOption($status, $this->formatStatus($status), $filters['status']);
        }
        echo '</select> ';

        echo '<label for="onesmtp-log-provider">' . esc_html__('Provider', 'onesmtp') . '</label> ';
        echo '<select id="onesmtp-log-provider" name="provider_id">';
        $this->renderOption('0', __('Any provider', 'onesmtp'), (string) $filters['provider_id']);
        foreach ($providers as $provider) {
            $providerId = (int) ($provider['id'] ?? 0);
            if ($providerId <= 0) {
                continue;
            }

            $this->renderOption((string) $providerId, $this->providerLabel($provider), (string) $filters['provider_id']);
        }
        echo '</select> ';

        echo '<label for="onesmtp-log-date-from">' . esc_html__('From', 'onesmtp') . '</label> ';
        echo '<input id="onesmtp-log-date-from" type="date" name="date_from" value="' . esc_attr($filters['date_from']) . '"> ';

        echo '<label for="onesmtp-log-date-to">' . esc_html__('To', 'onesmtp') . '</label> ';
        echo '<input id="onesmtp-log-date-to" type="date" name="date_to" value="' . esc_attr($filters['date_to']) . '"> ';
        echo '</p>';

        echo '<p>';
        echo '<label for="onesmtp-log-search">' . esc_html__('Search', 'onesmtp') . '</label> ';
        echo '<input id="onesmtp-log-search" type="search" name="s" value="' . esc_attr($filters['search']) . '" placeholder="' . esc_attr__('Lineage UUID, log ID, or recipient hash', 'onesmtp') . '" size="36"> ';

        echo '<label for="onesmtp-log-recipient-hash">' . esc_html__('Recipient hash', 'onesmtp') . '</label> ';
        echo '<input id="onesmtp-log-recipient-hash" type="search" name="recipient_hash" value="' . esc_attr($filters['recipient_hash']) . '" pattern="[a-fA-F0-9]{64}" size="36"> ';

        echo '<label for="onesmtp-logs-per-page">' . esc_html__('Per page', 'onesmtp') . '</label> ';
        echo '<select id="onesmtp-logs-per-page" name="onesmtp_logs_per_page">';
        foreach ([10, 25, 50, 100] as $option) {
            $this->renderOption((string) $option, (string) $option, (string) $perPage);
        }
        echo '</select> ';

        submit_button(__('Filter logs', 'onesmtp'), 'secondary', 'submit', false);

        $exportUrl = add_query_arg(
            $this->urlArgsForFilters($filters, 1, $perPage) + [
                self::ACTION_NAME => self::EXPORT_ACTION,
                self::EXPORT_NONCE_NAME => wp_create_nonce(self::EXPORT_ACTION),
            ],
            admin_url('admin.php')
        );
        echo ' <a class="button" href="' . esc_url($exportUrl) . '" aria-label="' . esc_attr__('Export filtered log CSV', 'onesmtp') . '">' . esc_html__('Export CSV', 'onesmtp') . '</a>';
        echo '</p>';
        echo '</form>';
    }

    private function renderOption(string $value, string $label, string $selected): void
    {
        echo '<option value="' . esc_attr($value) . '"' . ($value === $selected ? ' selected="selected"' : '') . '>' . esc_html($label) . '</option>';
    }

    /**
     * @param array{status:string,provider_id:int,date_from:string,date_to:string,recipient_hash:string,search:string} $filters
     */
    private function renderPagination(array $filters, int $page, int $perPage, int $total): void
    {
        $maxPage = max(1, (int) ceil($total / $perPage));
        if ($maxPage <= 1) {
            return;
        }

        echo '<p class="tablenav-pages">';
        if ($page > 1) {
            echo '<a class="button" href="' . esc_url(add_query_arg($this->urlArgsForFilters($filters, $page - 1, $perPage), admin_url('admin.php'))) . '" aria-label="' . esc_attr__('Previous log page', 'onesmtp') . '">' . esc_html__('Previous', 'onesmtp') . '</a> ';
        }

        echo '<span>' . esc_html(
            sprintf(
                /* translators: 1: current page, 2: total pages. */
                __('Page %1$d of %2$d', 'onesmtp'),
                $page,
                $maxPage
            )
        ) . '</span>';

        if ($page < $maxPage) {
            echo ' <a class="button" href="' . esc_url(add_query_arg($this->urlArgsForFilters($filters, $page + 1, $perPage), admin_url('admin.php'))) . '" aria-label="' . esc_attr__('Next log page', 'onesmtp') . '">' . esc_html__('Next', 'onesmtp') . '</a>';
        }
        echo '</p>';
    }

    /**
     * @return array{status:string,provider_id:int,date_from:string,date_to:string,recipient_hash:string,search:string}
     */
    private function filtersFromRequest(): array
    {
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash((string) $_GET['status'])) : '';
        $providerId = isset($_GET['provider_id']) ? absint(wp_unslash((string) $_GET['provider_id'])) : 0;
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeDate(wp_unslash((string) $_GET['date_from'])) : '';
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeDate(wp_unslash((string) $_GET['date_to'])) : '';
        $recipientHash = isset($_GET['recipient_hash']) ? strtolower(sanitize_text_field(wp_unslash((string) $_GET['recipient_hash']))) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';

        if (! $this->isSha256Hash($recipientHash)) {
            $recipientHash = '';
        }

        return [
            'status' => $status,
            'provider_id' => $providerId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'recipient_hash' => $recipientHash,
            'search' => $search,
        ];
    }

    private function sanitizeDate(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function isSha256Hash(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    /**
     * @param array{status:string,provider_id:int,date_from:string,date_to:string,recipient_hash:string,search:string} $filters
     * @return array<string,string|int>
     */
    private function urlArgsForFilters(array $filters, int $page, int $perPage): array
    {
        $args = [
            'page' => 'onesmtp',
            'onesmtp_log_page' => max(1, $page),
            'onesmtp_logs_per_page' => max(1, min(self::MAX_PER_PAGE, $perPage)),
        ];

        if ($filters['status'] !== '') {
            $args['status'] = $filters['status'];
        }
        if ($filters['provider_id'] > 0) {
            $args['provider_id'] = $filters['provider_id'];
        }
        if ($filters['date_from'] !== '') {
            $args['date_from'] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $args['date_to'] = $filters['date_to'];
        }
        if ($filters['recipient_hash'] !== '') {
            $args['recipient_hash'] = $filters['recipient_hash'];
        }
        if ($filters['search'] !== '') {
            $args['s'] = $filters['search'];
        }

        return $args;
    }

    private function handleCsvExport(): void
    {
        if (! Capabilities::canViewLogs()) {
            wp_die(
                esc_html__('You do not have permission to export OneSMTP logs.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $nonce = isset($_GET[self::EXPORT_NONCE_NAME]) ? sanitize_text_field(wp_unslash((string) $_GET[self::EXPORT_NONCE_NAME])) : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, self::EXPORT_ACTION)) {
            wp_die(
                esc_html__('The OneSMTP log export link has expired. Refresh the page and try again.', 'onesmtp'),
                esc_html__('OneSMTP export denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $filters = $this->filtersFromRequest();
        $messages = $this->messages->listFilteredWithAttemptCounts($filters, 1, min(self::MAX_PER_PAGE, self::EXPORT_LIMIT));
        $remaining = self::EXPORT_LIMIT - count($messages);
        $page = 2;
        while ($remaining > 0) {
            $next = $this->messages->listFilteredWithAttemptCounts($filters, $page, min(self::MAX_PER_PAGE, $remaining));
            if ($next === []) {
                break;
            }

            array_push($messages, ...$next);
            $remaining = self::EXPORT_LIMIT - count($messages);
            $page++;
        }

        if (! headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=onesmtp-email-logs.csv');
            header('X-Content-Type-Options: nosniff');
        }

        $this->outputCsv($messages);

        if ($this->isTestingRuntime()) {
            throw new \RuntimeException('OneSMTP log CSV exported.');
        }

        exit;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     */
    private function outputCsv(array $messages): void
    {
        $handle = fopen('php://output', 'w');
        if (! is_resource($handle)) {
            return;
        }

        fputcsv(
            $handle,
            [
                'message_id',
                'lineage_uuid',
                'status',
                'provider',
                'attempt_count',
                'max_attempts',
                'attachment_summary',
                'recipient_summary',
                'next_retry_at',
                'created_at',
                'updated_at',
            ],
            ',',
            '"',
            '\\'
        );

        foreach ($messages as $message) {
            fputcsv(
                $handle,
                [
                    (string) ((int) ($message['id'] ?? 0)),
                    $this->csvCell((string) ($message['message_uuid'] ?? '')),
                    $this->csvCell($this->formatStatus((string) ($message['status'] ?? ''))),
                    $this->csvCell($this->formatProvider((int) ($message['selected_provider_id'] ?? 0))),
                    (string) ((int) ($message['attempt_count'] ?? $message['current_attempt'] ?? 0)),
                    (string) ((int) ($message['max_attempts'] ?? 0)),
                    $this->csvCell($this->formatAttachmentSummary($this->payloadFor($message))),
                    $this->csvCell($this->formatRecipientSummary($this->payloadFor($message))),
                    $this->csvCell((string) ($message['next_retry_at'] ?? '')),
                    $this->csvCell((string) ($message['created_at'] ?? '')),
                    $this->csvCell((string) ($message['updated_at'] ?? '')),
                ],
                ',',
                '"',
                '\\'
            );
        }

        fclose($handle);
    }

    private function csvCell(string $value): string
    {
        return $this->redactor->redactText($value, 300);
    }

    /**
     * @param array{status:string,provider_id:int,date_from:string,date_to:string,recipient_hash:string,search:string} $filters
     */
    private function hasActiveFilters(array $filters): bool
    {
        return $filters['status'] !== ''
            || $filters['provider_id'] > 0
            || $filters['date_from'] !== ''
            || $filters['date_to'] !== ''
            || $filters['recipient_hash'] !== ''
            || $filters['search'] !== '';
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
        $eligibleProviders = $this->eligibleProviders();

        echo '<h3>' . esc_html__('Message detail', 'onesmtp') . '</h3>';
        echo '<table class="widefat striped"><tbody>';
        $this->renderDetailRow(__('Message ID', 'onesmtp'), '#' . (string) $messageId);
        $this->renderDetailRow(__('Lineage UUID', 'onesmtp'), (string) ($message['message_uuid'] ?? ''));
        $this->renderDetailRow(__('Status', 'onesmtp'), $this->formatStatus((string) ($message['status'] ?? '')));
        $this->renderDetailRow(__('Selected provider', 'onesmtp'), $this->formatProvider((int) ($message['selected_provider_id'] ?? 0)));
        $this->renderDetailRow(__('Source', 'onesmtp'), $this->formatSourceAttribution($payload));
        $this->renderDetailRow(__('Attempts', 'onesmtp'), (string) count($attempts) . ' / ' . (string) ((int) ($message['max_attempts'] ?? 0)));
        $this->renderDetailRow(__('Attachments', 'onesmtp'), $this->formatAttachmentSummary($payload));
        $this->renderDetailRow(__('Recipients', 'onesmtp'), $this->formatRecipientSummary($payload));
        $this->renderDetailRow(__('Next retry', 'onesmtp'), (string) ($message['next_retry_at'] ?? __('None scheduled', 'onesmtp')));
        $this->renderDetailRow(__('Created', 'onesmtp'), (string) ($message['created_at'] ?? ''));
        $this->renderDetailRow(__('Updated', 'onesmtp'), (string) ($message['updated_at'] ?? ''));
        echo '</tbody></table>';

        $this->renderAttachmentMetadata($payload);
        $this->renderResendForm($messageId, $payload, $eligibleProviders);
        $this->renderForwardForm($message);

        echo '<h4>' . esc_html__('Attempt lineage', 'onesmtp') . '</h4>';
        if ($attempts === []) {
            echo '<p>' . esc_html__('No delivery attempts have been recorded for this message yet.', 'onesmtp') . '</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Attempt', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Provider', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Trigger', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Result', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Safe error context', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Provider message', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Latency', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Created', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($attempts as $attempt) {
            echo '<tr>';
            echo '<th scope="row">' . esc_html((string) ((int) ($attempt['attempt_no'] ?? 0))) . '</th>';
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

    /**
     * @param array<string,mixed> $payload
     * @param array<int,array<string,mixed>> $providers
     */
    private function renderResendForm(int $messageId, array $payload, array $providers): void
    {
        echo '<h4>' . esc_html__('Manual resend', 'onesmtp') . '</h4>';

        if (! Capabilities::canResendEmails()) {
            echo '<p>' . esc_html__('You do not have permission to resend this message.', 'onesmtp') . '</p>';

            return;
        }

        if ($payload === []) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('This message cannot be resent because the stored safe payload is unavailable.', 'onesmtp') . '</p></div>';

            return;
        }

        if ($providers === []) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('No eligible active providers are available for manual resend.', 'onesmtp') . '</p></div>';

            return;
        }

        echo '<form method="post" class="onesmtp-resend-form">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="resend">';
        echo '<input type="hidden" name="' . esc_attr(self::DETAIL_PARAM) . '" value="' . esc_attr((string) $messageId) . '">';
        echo '<p><label for="onesmtp-resend-provider">' . esc_html__('Provider override', 'onesmtp') . '</label><br>';
        echo '<select id="onesmtp-resend-provider" name="provider_id">';
        echo '<option value="0">' . esc_html__('Use normal provider selection', 'onesmtp') . '</option>';

        foreach ($providers as $provider) {
            $providerId = (int) ($provider['id'] ?? 0);
            if ($providerId <= 0) {
                continue;
            }

            echo '<option value="' . esc_attr((string) $providerId) . '">' . esc_html($this->providerLabel($provider)) . '</option>';
        }

        echo '</select></p>';
        submit_button(__('Resend message', 'onesmtp'), 'secondary', 'submit', false);
        echo '</form>';
    }

    /**
     * @param array<string,mixed> $message
     */
    private function renderForwardForm(array $message): void
    {
        $messageId = (int) ($message['id'] ?? 0);
        if ($messageId <= 0) {
            return;
        }

        echo '<h4>' . esc_html__('Forward safe summary', 'onesmtp') . '</h4>';

        if (! Capabilities::canResendEmails()) {
            echo '<p>' . esc_html__('You do not have permission to forward this log summary.', 'onesmtp') . '</p>';

            return;
        }

        $to = $this->safeForwardAddress();
        if ($to === '') {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('A valid WordPress admin email is required before OneSMTP can forward log summaries.', 'onesmtp') . '</p></div>';

            return;
        }

        echo '<p>' . esc_html(
            sprintf(
                /* translators: %s: safe destination email address. */
                __('Send a redacted delivery summary to the verified site admin address: %s.', 'onesmtp'),
                $to
            )
        ) . '</p>';
        echo '<form method="post" class="onesmtp-forward-summary-form">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<input type="hidden" name="' . esc_attr(self::ACTION_NAME) . '" value="' . esc_attr(self::FORWARD_ACTION) . '">';
        echo '<input type="hidden" name="' . esc_attr(self::DETAIL_PARAM) . '" value="' . esc_attr((string) $messageId) . '">';
        submit_button(__('Forward safe summary', 'onesmtp'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderDetailRow(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function renderAttachmentMetadata(array $payload): void
    {
        $attachmentLog = $this->attachmentLogFor($payload);
        if ($attachmentLog === null || (int) ($attachmentLog['count'] ?? 0) <= 0) {
            return;
        }

        $items = isset($attachmentLog['items']) && is_array($attachmentLog['items']) ? $attachmentLog['items'] : [];
        if ($items === []) {
            return;
        }

        echo '<h4>' . esc_html__('Attachment metadata', 'onesmtp') . '</h4>';
        echo '<p>' . esc_html__('OneSMTP stores metadata only. File contents and raw server paths are not stored or displayed.', 'onesmtp') . '</p>';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Filename', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Extension', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Size', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            echo '<tr>';
            echo '<th scope="row">' . esc_html($this->safeAttachmentText((string) ($item['filename'] ?? __('Attachment', 'onesmtp')), 120)) . '</th>';
            echo '<td>' . esc_html(sanitize_key((string) ($item['extension'] ?? ''))) . '</td>';
            echo '<td>' . esc_html($this->formatAttachmentSize($item['size_bytes'] ?? null)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
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

    private function formatAttachmentSummary(array $payload): string
    {
        $attachmentLog = $this->attachmentLogFor($payload);
        if ($attachmentLog === null) {
            return __('Not logged', 'onesmtp');
        }

        $count = max(0, (int) ($attachmentLog['count'] ?? 0));
        if ($count === 0) {
            return __('0 attachments', 'onesmtp');
        }

        $items = isset($attachmentLog['items']) && is_array($attachmentLog['items']) ? $attachmentLog['items'] : [];
        $names = [];
        foreach (array_slice($items, 0, 3) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->safeAttachmentText((string) ($item['filename'] ?? ''), 60);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $suffix = ! empty($attachmentLog['truncated']) || $count > count($names) ? ', +' . (string) max(0, $count - count($names)) . ' more' : '';
        $label = sprintf(
            /* translators: %d: attachment count. */
            $count === 1 ? __('%d attachment', 'onesmtp') : __('%d attachments', 'onesmtp'),
            $count
        );

        return $names !== [] ? $label . ': ' . implode(', ', $names) . $suffix : $label;
    }

    private function attachmentLogFor(array $payload): ?array
    {
        $attachmentLog = $payload[AttachmentLogSanitizer::PAYLOAD_KEY] ?? null;

        return is_array($attachmentLog) && ! empty($attachmentLog['enabled']) ? $attachmentLog : null;
    }

    private function formatAttachmentSize(mixed $size): string
    {
        if (! is_numeric($size) || (int) $size < 0) {
            return __('Unknown', 'onesmtp');
        }

        $bytes = (int) $size;
        if ($bytes < 1024) {
            return sprintf(
                /* translators: %d: file size in bytes. */
                __('%d bytes', 'onesmtp'),
                $bytes
            );
        }

        return sprintf(
            /* translators: %.1f: file size in kilobytes. */
            __('%.1f KB', 'onesmtp'),
            $bytes / 1024
        );
    }

    private function safeAttachmentText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/', ' ', sanitize_text_field($value)) ?? '');
        $value = str_replace(['/', '\\'], '', $value);

        return $this->shortText($value, $limit);
    }

    private function formatSourceAttribution(array $payload): string
    {
        $source = $payload['onesmtp_source'] ?? null;
        if (! is_array($source)) {
            return __('Unknown source', 'onesmtp');
        }

        $type = isset($source['type']) ? sanitize_key((string) $source['type']) : '';
        $name = isset($source['name']) && is_scalar($source['name']) ? $this->shortText((string) $source['name'], 80) : '';
        $slug = isset($source['slug']) && is_scalar($source['slug']) ? sanitize_key((string) $source['slug']) : '';

        if ($name === '' && $slug !== '') {
            $name = $this->labelFromSlug($slug);
        }

        if ($type === 'plugin') {
            return $name !== ''
                ? sprintf(
                    /* translators: %s: plugin name. */
                    __('Plugin: %s', 'onesmtp'),
                    $name
                )
                : __('Plugin: Unknown plugin', 'onesmtp');
        }

        if ($type === 'theme') {
            return $name !== ''
                ? sprintf(
                    /* translators: %s: theme name. */
                    __('Theme: %s', 'onesmtp'),
                    $name
                )
                : __('Theme: Unknown theme', 'onesmtp');
        }

        if ($type === 'core') {
            return __('WordPress core', 'onesmtp');
        }

        return __('Unknown source', 'onesmtp');
    }

    private function labelFromSlug(string $slug): string
    {
        $label = str_replace(['-', '_'], ' ', $slug);

        return ucwords($label);
    }

    private function shortText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/', ' ', sanitize_text_field($value)) ?? '');
        $limit = max(10, $limit);

        if (strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(substr($value, 0, $limit - 3)) . '...';
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

    /**
     * @return array<int,int>
     */
    private function postedMessageIds(): array
    {
        $raw = $_POST[self::BULK_MESSAGE_IDS_PARAM] ?? [];
        if (! is_array($raw)) {
            $raw = [$raw];
        }

        $messageIds = [];
        foreach ($raw as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $messageId = absint(wp_unslash((string) $value));
            if ($messageId > 0) {
                $messageIds[$messageId] = $messageId;
            }
        }

        return array_slice(array_values($messageIds), 0, self::MAX_BULK_MESSAGES);
    }

    private function safeForwardAddress(): string
    {
        $email = sanitize_email((string) get_option('admin_email'));
        if ($email === '') {
            return '';
        }

        if (function_exists('is_email') && ! is_email($email)) {
            return '';
        }

        return $email;
    }

    /**
     * @param array<string,mixed> $message
     */
    private function safeForwardBody(array $message): string
    {
        $messageId = (int) ($message['id'] ?? 0);
        $payload = $this->payloadFor($message);
        $attempts = $messageId > 0 ? $this->attempts->listByMessageId($messageId) : [];
        $lastAttempt = $attempts !== [] ? $attempts[count($attempts) - 1] : [];
        $lines = [
            'OneSMTP safe log summary',
            '',
            'Message ID: #' . (string) $messageId,
            'Lineage UUID: ' . $this->shortCode((string) ($message['message_uuid'] ?? '')),
            'Status: ' . $this->formatStatus((string) ($message['status'] ?? '')),
            'Provider: ' . $this->formatProvider((int) ($message['selected_provider_id'] ?? 0)),
            'Source: ' . $this->formatSourceAttribution($payload),
            'Recipients: ' . $this->formatRecipientSummary($payload),
            'Attachments: ' . $this->formatAttachmentSummary($payload),
            'Attempts: ' . (string) count($attempts) . ' / ' . (string) ((int) ($message['max_attempts'] ?? 0)),
            'Next retry: ' . (string) ($message['next_retry_at'] ?? __('None scheduled', 'onesmtp')),
            'Created: ' . (string) ($message['created_at'] ?? ''),
            'Updated: ' . (string) ($message['updated_at'] ?? ''),
            'Latest safe error context: ' . ($lastAttempt !== [] ? $this->formatError($lastAttempt) : __('None', 'onesmtp')),
            '',
            'Privacy boundary: this summary excludes message subject, body, raw recipients, raw headers, provider secrets, and attachment paths or contents.',
        ];

        return implode("\n", array_map(fn (string $line): string => $this->redactor->redactText($line, 400), $lines));
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function eligibleProviders(): array
    {
        return array_values(
            array_filter(
                $this->providers->getActiveProviders(),
                fn (array $provider): bool => $this->providerIsEligible($provider)
            )
        );
    }

    private function isEligibleProvider(int $providerId): bool
    {
        foreach ($this->eligibleProviders() as $provider) {
            if ((int) ($provider['id'] ?? 0) === $providerId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $provider
     */
    private function providerIsEligible(array $provider): bool
    {
        if ((int) ($provider['is_active'] ?? 0) !== 1) {
            return false;
        }

        if ((string) ($provider['circuit_state'] ?? 'closed') !== 'open') {
            return true;
        }

        $until = isset($provider['circuit_until']) ? strtotime((string) $provider['circuit_until']) : false;

        return ! is_int($until) || $until <= time();
    }

    /**
     * @param array<string,mixed> $provider
     */
    private function providerLabel(array $provider): string
    {
        $name = trim((string) ($provider['name'] ?? ''));
        $type = trim((string) ($provider['adapter_type'] ?? ''));
        $id = (int) ($provider['id'] ?? 0);

        if ($name === '') {
            $name = sprintf(
                /* translators: %d: provider id. */
                __('Provider #%d', 'onesmtp'),
                $id
            );
        }

        return trim($name . ($type !== '' ? ' (' . $type . ')' : ''));
    }

    private function renderActionNotices(): void
    {
        $this->renderResendNotice();
        $this->renderBulkNotice();
        $this->renderForwardNotice();
    }

    private function renderResendNotice(): void
    {
        $status = isset($_GET['onesmtp_resend_status']) ? sanitize_key(wp_unslash((string) $_GET['onesmtp_resend_status'])) : '';
        if ($status === '') {
            return;
        }

        $messages = [
            'resent' => __('Manual resend completed. Review the attempt lineage for the recorded provider and result.', 'onesmtp'),
            'failed' => __('Manual resend failed safely. Review the latest attempt lineage for sanitized failure context.', 'onesmtp'),
            'missing' => __('The requested message could not be found for resend.', 'onesmtp'),
            'ineligible_provider' => __('The selected provider is not eligible for manual resend.', 'onesmtp'),
        ];

        $this->renderActionNotice($messages[$status] ?? __('Manual resend action could not be completed.', 'onesmtp'), $status === 'resent');
    }

    private function renderBulkNotice(): void
    {
        $status = isset($_GET['onesmtp_bulk_resend_status']) ? sanitize_key(wp_unslash((string) $_GET['onesmtp_bulk_resend_status'])) : '';
        if ($status === '') {
            return;
        }

        $resent = isset($_GET['onesmtp_bulk_resent']) ? absint(wp_unslash((string) $_GET['onesmtp_bulk_resent'])) : 0;
        $failed = isset($_GET['onesmtp_bulk_failed']) ? absint(wp_unslash((string) $_GET['onesmtp_bulk_failed'])) : 0;
        $messages = [
            'empty' => __('Select at least one failed message before applying bulk resend.', 'onesmtp'),
            'resent' => sprintf(
                /* translators: %d: resent message count. */
                $resent === 1 ? __('Bulk resend completed for %d failed message.', 'onesmtp') : __('Bulk resend completed for %d failed messages.', 'onesmtp'),
                $resent
            ),
            'partial' => sprintf(
                /* translators: 1: resent message count, 2: failed or skipped message count. */
                __('Bulk resend completed for %1$d messages; %2$d selected messages failed or were skipped.', 'onesmtp'),
                $resent,
                $failed
            ),
            'failed' => __('Bulk resend did not complete for the selected messages. Only failed logs with stored safe payloads can be resent.', 'onesmtp'),
        ];

        $this->renderActionNotice($messages[$status] ?? __('Bulk resend action could not be completed.', 'onesmtp'), $status === 'resent');
    }

    private function renderForwardNotice(): void
    {
        $status = isset($_GET['onesmtp_forward_status']) ? sanitize_key(wp_unslash((string) $_GET['onesmtp_forward_status'])) : '';
        if ($status === '') {
            return;
        }

        $messages = [
            'forwarded' => __('Safe log summary forwarded to the verified site admin address.', 'onesmtp'),
            'failed' => __('Safe log summary could not be forwarded.', 'onesmtp'),
            'missing' => __('The requested message could not be found for forwarding.', 'onesmtp'),
            'unsafe_recipient' => __('A valid WordPress admin email is required before forwarding log summaries.', 'onesmtp'),
        ];

        $this->renderActionNotice($messages[$status] ?? __('Forward action could not be completed.', 'onesmtp'), $status === 'forwarded');
    }

    private function renderActionNotice(string $message, bool $success): void
    {
        echo '<div class="notice ' . esc_attr($success ? 'notice-success' : 'notice-error') . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function redirect(string $status, int $messageId): void
    {
        $url = add_query_arg(
            [
                self::DETAIL_PARAM => $messageId,
                'onesmtp_resend_status' => $status,
            ],
            admin_url('admin.php?page=onesmtp#onesmtp-logs')
        );

        wp_safe_redirect($url);
        if ($this->isTestingRuntime()) {
            throw new \RuntimeException('OneSMTP log admin redirected.');
        }

        exit;
    }

    private function redirectBulk(string $status, int $resent, int $failed): void
    {
        $url = add_query_arg(
            [
                'onesmtp_bulk_resend_status' => $status,
                'onesmtp_bulk_resent' => max(0, $resent),
                'onesmtp_bulk_failed' => max(0, $failed),
            ],
            admin_url('admin.php?page=onesmtp#onesmtp-logs')
        );

        wp_safe_redirect($url);
        if ($this->isTestingRuntime()) {
            throw new \RuntimeException('OneSMTP log admin redirected.');
        }

        exit;
    }

    private function redirectForward(string $status, int $messageId): void
    {
        $url = add_query_arg(
            [
                self::DETAIL_PARAM => $messageId,
                'onesmtp_forward_status' => $status,
            ],
            admin_url('admin.php?page=onesmtp#onesmtp-logs')
        );

        wp_safe_redirect($url);
        if ($this->isTestingRuntime()) {
            throw new \RuntimeException('OneSMTP log admin redirected.');
        }

        exit;
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
        $category = sanitize_key((string) ($attempt['failure_category'] ?? ''));
        if ($code === '' && $message === '' && $category === '') {
            return __('None', 'onesmtp');
        }

        $context = trim($code . ($message !== '' ? ': ' . $message : ''));
        if ($category !== '') {
            $context = trim(sprintf('category=%s%s', $category, $context !== '' ? ' ' . $context : ''));
        }

        return $this->redactor->redactText($context, self::ERROR_LIMIT);
    }

    private function shortCode(string $value): string
    {
        $value = trim($this->redactor->redactText($value, 80));

        return $value !== '' ? $value : __('Unavailable', 'onesmtp');
    }

    private function isTestingRuntime(): bool
    {
        return defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING');
    }
}
