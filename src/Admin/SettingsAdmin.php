<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use InvalidArgumentException;
use OneSMTP\Alerts\FailureAlertSettings;
use OneSMTP\Alerts\FailureAlertSettingsRepository;
use OneSMTP\Settings\RateLimitSettings;
use OneSMTP\Settings\RateLimitSettingsRepository;
use OneSMTP\Settings\SenderIdentity;
use OneSMTP\Settings\SenderIdentityRepository;
use RuntimeException;

final class SettingsAdmin
{
    private const ACTION_NAME = 'onesmtp_save_settings';
    private const NONCE_NAME = 'onesmtp_settings_nonce';

    public function __construct(
        private ?SenderIdentityRepository $senderIdentity = null,
        private ?RateLimitSettingsRepository $rateLimits = null,
        private ?FailureAlertSettingsRepository $failureAlerts = null
    ) {
        $this->senderIdentity = $senderIdentity ?? new SenderIdentityRepository();
        $this->rateLimits = $rateLimits ?? new RateLimitSettingsRepository();
        $this->failureAlerts = $failureAlerts ?? new FailureAlertSettingsRepository();
    }

    public function handleRequest(): void
    {
        if (($GLOBALS['pagenow'] ?? '') !== 'admin.php') {
            return;
        }

        $action = (string) ($_POST['onesmtp_settings_action'] ?? '');

        try {
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

    private function redirect(string $status, string $message = ''): void
    {
        $args = ['onesmtp_settings_status' => $status];
        if ($message !== '') {
            $args['onesmtp_settings_message'] = $message;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=onesmtp#onesmtp-settings')));
        throw new RuntimeException('OneSMTP settings admin redirected.');
    }
}
