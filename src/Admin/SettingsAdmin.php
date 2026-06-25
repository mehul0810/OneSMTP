<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use InvalidArgumentException;
use OneSMTP\Alerts\FailureAlertSettings;
use OneSMTP\Alerts\FailureAlertSettingsRepository;
use OneSMTP\Core\Capabilities;
use OneSMTP\Settings\BackgroundSendingSettings;
use OneSMTP\Settings\BackgroundSendingSettingsRepository;
use OneSMTP\Settings\RateLimitSettings;
use OneSMTP\Settings\RateLimitSettingsRepository;
use OneSMTP\Settings\SenderIdentity;
use OneSMTP\Settings\SenderIdentityRepository;
use OneSMTP\Settings\SettingsTransferService;
use RuntimeException;

final class SettingsAdmin
{
    private const ACTION_NAME = 'onesmtp_save_settings';
    private const NONCE_NAME = 'onesmtp_settings_nonce';
    private const EXPORT_ACTION = 'export_settings';
    private const IMPORT_ACTION = 'import_settings';
    private const EXPORT_NONCE_NAME = 'onesmtp_settings_export_nonce';
    private const IMPORT_NONCE_NAME = 'onesmtp_settings_import_nonce';

    public function __construct(
        private ?SenderIdentityRepository $senderIdentity = null,
        private ?RateLimitSettingsRepository $rateLimits = null,
        private ?FailureAlertSettingsRepository $failureAlerts = null,
        private ?BackgroundSendingSettingsRepository $backgroundSending = null,
        private ?SettingsTransferService $transfers = null
    ) {
        $this->senderIdentity = $senderIdentity ?? new SenderIdentityRepository();
        $this->rateLimits = $rateLimits ?? new RateLimitSettingsRepository();
        $this->failureAlerts = $failureAlerts ?? new FailureAlertSettingsRepository();
        $this->backgroundSending = $backgroundSending ?? new BackgroundSendingSettingsRepository();
        $this->transfers = $transfers ?? new SettingsTransferService();
    }

    public function handleRequest(): void
    {
        if (($GLOBALS['pagenow'] ?? '') !== 'admin.php') {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        if ($method === 'GET') {
            $action = isset($_GET['onesmtp_settings_action']) ? sanitize_key(wp_unslash((string) $_GET['onesmtp_settings_action'])) : '';
            if ($action === self::EXPORT_ACTION) {
                $this->handleExport();
            }

            return;
        }

        $action = isset($_POST['onesmtp_settings_action']) ? sanitize_key(wp_unslash((string) $_POST['onesmtp_settings_action'])) : '';

        try {
            if ($action === self::IMPORT_ACTION) {
                $this->handleImport();
                return;
            }

            if ($action === 'save_rate_limits') {
                $this->rateLimits->save(RateLimitSettings::fromArray([
                    'per_minute' => isset($_POST['rate_limit_per_minute']) ? wp_unslash((string) $_POST['rate_limit_per_minute']) : 0,
                    'per_hour' => isset($_POST['rate_limit_per_hour']) ? wp_unslash((string) $_POST['rate_limit_per_hour']) : 0,
                    'per_day' => isset($_POST['rate_limit_per_day']) ? wp_unslash((string) $_POST['rate_limit_per_day']) : 0,
                ]));
                $this->redirect('rate_limits_saved');
                return;
            }

            if ($action === 'save_failure_alerts') {
                $this->failureAlerts->save(FailureAlertSettings::fromArray([
                    'email_enabled' => isset($_POST['failure_alert_email_enabled']),
                    'email_recipients' => isset($_POST['failure_alert_email_recipients']) ? wp_unslash((string) $_POST['failure_alert_email_recipients']) : '',
                    'webhook_enabled' => isset($_POST['failure_alert_webhook_enabled']),
                    'webhook_url' => isset($_POST['failure_alert_webhook_url']) ? wp_unslash((string) $_POST['failure_alert_webhook_url']) : '',
                    'throttle_seconds' => isset($_POST['failure_alert_throttle_seconds']) ? wp_unslash((string) $_POST['failure_alert_throttle_seconds']) : 900,
                ]));
                $this->redirect('failure_alerts_saved');
                return;
            }

            if ($action === 'save_background_sending') {
                $this->backgroundSending->save(BackgroundSendingSettings::fromArray([
                    'enabled' => isset($_POST['background_sending_enabled']),
                ]));
                $this->redirect('background_sending_saved');
                return;
            }

            if ($action !== 'save_sender_identity') {
                return;
            }

            $identity = SenderIdentity::fromArray([
                'from_email' => isset($_POST['from_email']) ? wp_unslash((string) $_POST['from_email']) : '',
                'from_name' => isset($_POST['from_name']) ? wp_unslash((string) $_POST['from_name']) : '',
                'reply_to' => isset($_POST['reply_to']) ? wp_unslash((string) $_POST['reply_to']) : '',
                'bcc' => isset($_POST['bcc']) ? wp_unslash((string) $_POST['bcc']) : '',
                'force_from_email' => isset($_POST['force_from_email']),
                'force_from_name' => isset($_POST['force_from_name']),
                'force_reply_to' => isset($_POST['force_reply_to']),
                'force_bcc' => isset($_POST['force_bcc']),
            ]);

            $this->senderIdentity->save($identity);
            $this->redirect('saved');
        } catch (InvalidArgumentException $e) {
            $this->redirect('invalid', $e->getMessage());
        }
    }

    public function render(): void
    {
        $status = isset($_GET['onesmtp_settings_status']) ? sanitize_text_field(wp_unslash((string) $_GET['onesmtp_settings_status'])) : '';
        $message = isset($_GET['onesmtp_settings_message']) ? sanitize_text_field(wp_unslash((string) $_GET['onesmtp_settings_message'])) : '';

        if ($status === 'saved') {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Sender identity settings saved.', 'onesmtp') . '</p></div>';
        } elseif ($status === 'rate_limits_saved') {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Delivery rate limit settings saved.', 'onesmtp') . '</p></div>';
        } elseif ($status === 'failure_alerts_saved') {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Failure alert settings saved.', 'onesmtp') . '</p></div>';
        } elseif ($status === 'background_sending_saved') {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Background sending settings saved.', 'onesmtp') . '</p></div>';
        } elseif ($status === 'imported') {
            echo '<div class="notice notice-success inline"><p>' . esc_html($message !== '' ? $message : __('OneSMTP settings imported. Secrets and recipient fields were excluded.', 'onesmtp')) . '</p></div>';
        } elseif ($status === 'invalid') {
            echo '<div class="notice notice-error inline"><p>' . esc_html($message !== '' ? $message : __('OneSMTP settings could not be saved.', 'onesmtp')) . '</p></div>';
        }

        $identity = $this->senderIdentity->get();
        $values = $identity->toArray();

        echo '<p>' . esc_html__('Configure default sender headers for outgoing WordPress mail. Existing headers are preserved unless the matching force option is enabled.', 'onesmtp') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=onesmtp#onesmtp-settings')) . '">';
        echo '<input type="hidden" name="onesmtp_settings_action" value="save_sender_identity">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->renderInput('from_email', __('From Email', 'onesmtp'), $values['from_email'], 'email');
        $this->renderInput('from_name', __('From Name', 'onesmtp'), $values['from_name']);
        $this->renderTextarea('reply_to', __('Reply-To', 'onesmtp'), implode("\n", $values['reply_to']));
        $this->renderTextarea('bcc', __('BCC', 'onesmtp'), implode("\n", $values['bcc']));
        echo '</tbody></table>';
        echo '<fieldset><legend>' . esc_html__('Force settings', 'onesmtp') . '</legend>';
        $this->renderCheckbox('force_from_email', __('Force From Email when a message already has a From header.', 'onesmtp'), (bool) $values['force_from_email']);
        $this->renderCheckbox('force_from_name', __('Force From Name when a message already has a From header.', 'onesmtp'), (bool) $values['force_from_name']);
        $this->renderCheckbox('force_reply_to', __('Force Reply-To when a message already has Reply-To.', 'onesmtp'), (bool) $values['force_reply_to']);
        $this->renderCheckbox('force_bcc', __('Force BCC when a message already has BCC.', 'onesmtp'), (bool) $values['force_bcc']);
        echo '</fieldset>';
        submit_button(__('Save sender identity', 'onesmtp'));
        echo '</form>';

        $limits = $this->rateLimits->get()->toArray();
        echo '<h3>' . esc_html__('Delivery rate limits', 'onesmtp') . '</h3>';
        echo '<p>' . esc_html__('Set optional site-wide delivery caps. When a cap is exhausted, OneSMTP defers queued mail until capacity is available. Use 0 to disable a limit.', 'onesmtp') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=onesmtp#onesmtp-settings')) . '">';
        echo '<input type="hidden" name="onesmtp_settings_action" value="save_rate_limits">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->renderNumberInput('rate_limit_per_minute', __('Per-minute limit', 'onesmtp'), (int) ($limits['per_minute'] ?? 0));
        $this->renderNumberInput('rate_limit_per_hour', __('Hourly limit', 'onesmtp'), (int) ($limits['per_hour'] ?? 0));
        $this->renderNumberInput('rate_limit_per_day', __('Daily limit', 'onesmtp'), (int) ($limits['per_day'] ?? 0));
        echo '</tbody></table>';
        submit_button(__('Save delivery rate limits', 'onesmtp'));
        echo '</form>';

        $backgroundSending = $this->backgroundSending->get();
        echo '<h3>' . esc_html__('Background sending', 'onesmtp') . '</h3>';
        echo '<p>' . esc_html__('Queue normal WordPress mail for asynchronous delivery so user-facing requests are not held by provider latency. Provider test emails and manual resends continue to run synchronously.', 'onesmtp') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=onesmtp#onesmtp-settings')) . '">';
        echo '<input type="hidden" name="onesmtp_settings_action" value="save_background_sending">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<fieldset>';
        $this->renderCheckbox('background_sending_enabled', __('Enable background sending for normal mail.', 'onesmtp'), $backgroundSending->isEnabled());
        echo '</fieldset>';
        submit_button(__('Save background sending', 'onesmtp'));
        echo '</form>';

        $alerts = $this->failureAlerts->get();
        $alertValues = $alerts->toArray();
        echo '<h3>' . esc_html__('Failure alerts', 'onesmtp') . '</h3>';
        if (! $alerts->hasEnabledChannel()) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Failure alerts are disabled until an email recipient or HTTPS webhook is enabled.', 'onesmtp') . '</p></div>';
        }
        echo '<p>' . esc_html__('Send privacy-safe alerts for terminal delivery failures. Alert payloads include IDs, hashes, status, provider summary, reason, and category only.', 'onesmtp') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=onesmtp#onesmtp-settings')) . '">';
        echo '<input type="hidden" name="onesmtp_settings_action" value="save_failure_alerts">';
        wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Admin email alerts', 'onesmtp') . '</th><td>';
        $this->renderCheckbox('failure_alert_email_enabled', __('Enable admin email alerts.', 'onesmtp'), ! empty($alertValues['email_enabled']));
        echo '</td></tr>';
        $this->renderTextarea('failure_alert_email_recipients', __('Alert recipients', 'onesmtp'), implode("\n", (array) ($alertValues['email_recipients'] ?? [])));
        echo '<tr><th scope="row">' . esc_html__('Webhook alerts', 'onesmtp') . '</th><td>';
        $this->renderCheckbox('failure_alert_webhook_enabled', __('Enable HTTPS webhook alerts.', 'onesmtp'), ! empty($alertValues['webhook_enabled']));
        echo '</td></tr>';
        $this->renderInput('failure_alert_webhook_url', __('Webhook URL', 'onesmtp'), (string) ($alertValues['webhook_url'] ?? ''), 'url', 'large-text code', 2048);
        echo '<tr><th scope="row"></th><td>';
        echo '<p class="description">' . esc_html__('Use an HTTPS endpoint. Raw recipients, headers, message bodies, provider credentials, and stored payload JSON are never sent.', 'onesmtp') . '</p>';
        echo '</td></tr>';
        $this->renderNumberInput('failure_alert_throttle_seconds', __('Throttle window in seconds', 'onesmtp'), (int) ($alertValues['throttle_seconds'] ?? 900));
        echo '</tbody></table>';
        submit_button(__('Save failure alerts', 'onesmtp'));
        echo '</form>';

        $this->renderImportExport();
    }

    private function renderInput(string $name, string $label, mixed $value, string $type = 'text', string $class = 'regular-text', int $maxlength = 0): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="' . esc_attr($type) . '" class="' . esc_attr($class) . '" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '"' . ($maxlength > 0 ? ' maxlength="' . esc_attr((string) $maxlength) . '"' : '') . '>';
        echo '</td></tr>';
    }

    private function renderTextarea(string $name, string $label, string $value): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<textarea class="large-text code" rows="3" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">' . esc_html($value) . '</textarea>';
        echo '<p class="description">' . esc_html__('Enter one email address per line or separate addresses with commas.', 'onesmtp') . '</p>';
        echo '</td></tr>';
    }

    private function renderCheckbox(string $name, string $label, bool $checked): void
    {
        echo '<p><label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . ($checked ? ' checked="checked"' : '') . '> ' . esc_html($label) . '</label></p>';
    }

    private function renderNumberInput(string $name, string $label, int $value): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="number" min="0" max="1000000" step="1" class="small-text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) max(0, $value)) . '">';
        echo '</td></tr>';
    }

    private function renderImportExport(): void
    {
        $downloadUrl = add_query_arg(
            [
                'page' => 'onesmtp',
                'onesmtp_settings_action' => self::EXPORT_ACTION,
                self::EXPORT_NONCE_NAME => wp_create_nonce(self::EXPORT_ACTION),
            ],
            admin_url('admin.php#onesmtp-settings')
        );

        echo '<h3>' . esc_html__('Settings import/export', 'onesmtp') . '</h3>';
        echo '<p>' . esc_html__('Download a privacy-safe JSON settings file for migration or backup. Provider secrets, credentials, tokens, passwords, API keys, webhook URLs, raw recipients, message bodies, raw headers, and payload JSON are excluded by default.', 'onesmtp') . '</p>';
        echo '<p><a class="button button-secondary" href="' . esc_url($downloadUrl) . '">' . esc_html__('Download safe settings export', 'onesmtp') . '</a></p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=onesmtp#onesmtp-settings')) . '">';
        echo '<input type="hidden" name="onesmtp_settings_action" value="' . esc_attr(self::IMPORT_ACTION) . '">';
        wp_nonce_field(self::IMPORT_ACTION, self::IMPORT_NONCE_NAME);
        echo '<p><label for="onesmtp-settings-import-json">' . esc_html__('Import safe settings JSON', 'onesmtp') . '</label></p>';
        echo '<textarea id="onesmtp-settings-import-json" class="large-text code" rows="10" name="onesmtp_settings_import_json" spellcheck="false"></textarea>';
        echo '<p class="description">' . esc_html__('Only supported non-secret settings are imported. Secret, credential, webhook URL, raw recipient, message body, raw header, and payload fields are ignored.', 'onesmtp') . '</p>';
        submit_button(__('Import safe settings', 'onesmtp'), 'secondary');
        echo '</form>';
    }

    private function handleExport(): void
    {
        if (! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to export OneSMTP settings.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $nonce = isset($_GET[self::EXPORT_NONCE_NAME]) ? sanitize_text_field(wp_unslash((string) $_GET[self::EXPORT_NONCE_NAME])) : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, self::EXPORT_ACTION)) {
            wp_die(
                esc_html__('The OneSMTP settings export link has expired. Refresh the page and try again.', 'onesmtp'),
                esc_html__('OneSMTP export denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $payload = $this->transfers->export();

        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename=onesmtp-safe-settings.json');
            header('X-Content-Type-Options: nosniff');
        }

        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($this->isTestingRuntime()) {
            throw new RuntimeException('OneSMTP settings exported.');
        }

        exit;
    }

    private function handleImport(): void
    {
        if (! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to import OneSMTP settings.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $nonce = isset($_POST[self::IMPORT_NONCE_NAME]) ? sanitize_text_field(wp_unslash((string) $_POST[self::IMPORT_NONCE_NAME])) : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, self::IMPORT_ACTION)) {
            wp_die(
                esc_html__('The OneSMTP settings import form has expired. Refresh the page and try again.', 'onesmtp'),
                esc_html__('OneSMTP import denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $json = isset($_POST['onesmtp_settings_import_json']) ? wp_unslash((string) $_POST['onesmtp_settings_import_json']) : '';
        $summary = $this->transfers->importJson($json, self::IMPORT_NONCE_NAME);
        $message = sprintf(
            /* translators: 1: imported settings group count, 2: imported provider count, 3: excluded field count. */
            __('Imported %1$d settings groups and %2$d providers. Excluded %3$d unsafe fields.', 'onesmtp'),
            count($summary['settings_groups']),
            $summary['providers_imported'],
            $summary['excluded_fields']
        );

        $this->redirect('imported', $message);
    }

    private function redirect(string $status, string $message = ''): void
    {
        $args = ['onesmtp_settings_status' => $status];
        if ($message !== '') {
            $args['onesmtp_settings_message'] = $message;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=onesmtp#onesmtp-settings')));
        throw new RuntimeException('OneSMTP settings admin redirected.');
    }

    private function isTestingRuntime(): bool
    {
        return defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING');
    }
}
