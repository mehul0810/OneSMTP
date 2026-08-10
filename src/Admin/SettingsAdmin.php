<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use InvalidArgumentException;
use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Alerts\FailureAlertSettings;
use OneSMTP\Alerts\FailureAlertSettingsRepository;
use OneSMTP\Core\Capabilities;
use OneSMTP\Core\RetentionPolicy;
use OneSMTP\Conflict\MailDeliveryOwnership;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Settings\AttachmentLoggingSettings;
use OneSMTP\Settings\AttachmentLoggingSettingsRepository;
use OneSMTP\Settings\BackgroundSendingSettings;
use OneSMTP\Settings\BackgroundSendingSettingsRepository;
use OneSMTP\Settings\RateLimitSettings;
use OneSMTP\Settings\RateLimitSettingsRepository;
use OneSMTP\Settings\SenderIdentity;
use OneSMTP\Settings\SenderIdentityRepository;
use OneSMTP\Settings\SettingsTransferService;
use OneSMTP\Settings\SimulationModeSettings;
use OneSMTP\Settings\SimulationModeSettingsRepository;
use OneSMTP\Summary\WeeklySummarySettings;
use OneSMTP\Summary\WeeklySummarySettingsRepository;
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
        private ?SettingsTransferService $transfers = null,
        private ?AttachmentLoggingSettingsRepository $attachmentLogging = null,
        private ?WeeklySummarySettingsRepository $weeklySummary = null,
        private ?AdminAuditLogger $auditLogger = null,
        private ?AdminRequest $request = null,
        private ?SimulationModeSettingsRepository $simulationMode = null,
        private ?MailDeliveryOwnership $deliveryOwnership = null,
        private ?FeatureGate $featureGate = null
    ) {
        $this->senderIdentity = $senderIdentity ?? new SenderIdentityRepository();
        $this->rateLimits = $rateLimits ?? new RateLimitSettingsRepository();
        $this->failureAlerts = $failureAlerts ?? new FailureAlertSettingsRepository();
        $this->backgroundSending = $backgroundSending ?? new BackgroundSendingSettingsRepository();
        $this->transfers = $transfers ?? new SettingsTransferService();
        $this->attachmentLogging = $attachmentLogging ?? new AttachmentLoggingSettingsRepository();
        $this->weeklySummary = $weeklySummary ?? new WeeklySummarySettingsRepository();
        $this->auditLogger = $auditLogger ?? new AdminAuditLogger();
        $this->request = $request ?? new AdminRequest();
        $this->simulationMode = $simulationMode ?? new SimulationModeSettingsRepository();
        $this->deliveryOwnership = $deliveryOwnership ?? new MailDeliveryOwnership();
        $this->featureGate = $featureGate ?? new FeatureGate();
    }

    public function handleRequest(): void
    {
        if (! in_array(($GLOBALS['pagenow'] ?? ''), ['admin.php', 'options-general.php'], true)) {
            return;
        }

        $method = $this->request->method();
        if ($method === 'GET') {
            $action = $this->request->getAction('onesmtp_settings_action');
            if ($action === self::EXPORT_ACTION) {
                $this->handleExport();
            }

            return;
        }

        $action = $this->request->postAction('onesmtp_settings_action');

        if ($action !== self::IMPORT_ACTION) {
            if (! Capabilities::canManage()) {
                wp_die(
                    esc_html__('You are not allowed to manage Aculect Mail settings.', 'onesmtp'),
                    esc_html__('Aculect Mail access denied', 'onesmtp'),
                    ['response' => 403]
                );
            }
            check_admin_referer(self::ACTION_NAME, self::NONCE_NAME);
        }

        try {
            if ($action === self::IMPORT_ACTION) {
                $this->handleImport();
                return;
            }

            if ($action === 'save_rate_limits') {
                $limits = RateLimitSettings::fromArray([
                    'per_minute' => isset($_POST['rate_limit_per_minute']) ? wp_unslash((string) $_POST['rate_limit_per_minute']) : 0,
                    'per_hour' => isset($_POST['rate_limit_per_hour']) ? wp_unslash((string) $_POST['rate_limit_per_hour']) : 0,
                    'per_day' => isset($_POST['rate_limit_per_day']) ? wp_unslash((string) $_POST['rate_limit_per_day']) : 0,
                ]);
                $this->rateLimits->save($limits);
                $this->auditLogger->logSettingsChange('rate_limits', array_merge(
                    $limits->toArray(),
                    ['source' => 'settings_admin']
                ));
                $this->redirect('rate_limits_saved');
                return;
            }

            if ($action === 'save_failure_alerts') {
                $alerts = FailureAlertSettings::fromArray([
                    'email_enabled' => isset($_POST['failure_alert_email_enabled']),
                    'email_recipients' => isset($_POST['failure_alert_email_recipients']) ? wp_unslash((string) $_POST['failure_alert_email_recipients']) : '',
                    'webhook_enabled' => isset($_POST['failure_alert_webhook_enabled']),
                    'webhook_url' => isset($_POST['failure_alert_webhook_url']) ? wp_unslash((string) $_POST['failure_alert_webhook_url']) : '',
                    'throttle_seconds' => isset($_POST['failure_alert_throttle_seconds']) ? wp_unslash((string) $_POST['failure_alert_throttle_seconds']) : 900,
                ]);
                $this->failureAlerts->save($alerts);
                $alertValues = $alerts->toArray();
                $this->auditLogger->logSettingsChange('failure_alerts', [
                    'source' => 'settings_admin',
                    'email_enabled' => ! empty($alertValues['email_enabled']),
                    'email_recipient_count' => count((array) ($alertValues['email_recipients'] ?? [])),
                    'webhook_enabled' => ! empty($alertValues['webhook_enabled']),
                    'throttle_seconds' => (int) ($alertValues['throttle_seconds'] ?? 0),
                ]);
                $this->redirect('failure_alerts_saved');
                return;
            }

            if ($action === 'save_advanced_alerts') {
                if (! $this->featureGate->isEnabled(FeatureGate::ADVANCED_ALERTS)) {
                    $this->redirect('invalid', __('Advanced alert routing requires an enabled Pro entitlement.', 'onesmtp'));
                    return;
                }

                $alerts = FailureAlertSettings::fromArray([
                    'advanced_enabled' => isset($_POST['failure_alert_advanced_enabled']),
                    'advanced_destinations' => isset($_POST['failure_alert_advanced_destinations']) ? wp_unslash((string) $_POST['failure_alert_advanced_destinations']) : '',
                    'escalation_failure_threshold' => isset($_POST['failure_alert_escalation_failure_threshold']) ? wp_unslash((string) $_POST['failure_alert_escalation_failure_threshold']) : 3,
                ]);
                $this->failureAlerts->save($alerts);
                $alertValues = $alerts->toArray();
                $this->auditLogger->logSettingsChange('advanced_alerts', [
                    'source' => 'settings_admin',
                    'enabled' => ! empty($alertValues['advanced_enabled']),
                    'destination_count' => count((array) ($alertValues['advanced_destinations'] ?? [])),
                    'escalation_failure_threshold' => (int) ($alertValues['escalation_failure_threshold'] ?? 0),
                ]);
                $this->redirect('advanced_alerts_saved');
                return;
            }

            if ($action === 'save_background_sending') {
                $background = BackgroundSendingSettings::fromArray([
                    'enabled' => isset($_POST['background_sending_enabled']),
                ]);
                $this->backgroundSending->save($background);
                $this->auditLogger->logSettingsChange('background_sending', [
                    'source' => 'settings_admin',
                    'enabled' => $background->toArray()['enabled'] ?? false,
                ]);
                $this->redirect('background_sending_saved');
                return;
            }

            if ($action === 'save_simulation_mode') {
                if (isset($_POST['simulation_mode_enabled']) && ! $this->deliveryOwnership->canAculectDeliver()) {
                    $this->redirect(
                        'simulation_mode_owner_conflict',
                        __('Simulation mode cannot be enabled while SureMail owns live WordPress delivery. Complete or pause the migration first.', 'onesmtp')
                    );
                    return;
                }
                $simulation = SimulationModeSettings::fromArray([
                    'enabled' => isset($_POST['simulation_mode_enabled']),
                ]);
                $this->simulationMode->save($simulation);
                $this->auditLogger->logSettingsChange('simulation_mode', [
                    'source' => 'settings_admin',
                    'enabled' => $simulation->isEnabled(),
                ]);
                $this->redirect('simulation_mode_saved');
                return;
            }

            if ($action === 'save_attachment_logging') {
                $attachmentLogging = AttachmentLoggingSettings::fromArray([
                    'enabled' => isset($_POST['attachment_logging_enabled']),
                ]);
                $this->attachmentLogging->save($attachmentLogging);
                $this->auditLogger->logSettingsChange('attachment_logging', [
                    'source' => 'settings_admin',
                    'enabled' => $attachmentLogging->toArray()['enabled'] ?? false,
                ]);
                $this->redirect('attachment_logging_saved');
                return;
            }

            if ($action === 'save_weekly_summary') {
                $summary = WeeklySummarySettings::fromArray([
                    'enabled' => isset($_POST['weekly_summary_enabled']),
                    'email_recipients' => isset($_POST['weekly_summary_email_recipients']) ? wp_unslash((string) $_POST['weekly_summary_email_recipients']) : '',
                ]);
                $this->weeklySummary->save($summary);
                $summaryValues = $summary->toArray();
                $this->auditLogger->logSettingsChange('weekly_summary', [
                    'source' => 'settings_admin',
                    'enabled' => ! empty($summaryValues['enabled']),
                    'recipient_count' => count((array) ($summaryValues['email_recipients'] ?? [])),
                ]);
                $this->redirect('weekly_summary_saved');
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
            $values = $identity->toArray();
            $this->auditLogger->logSettingsChange('sender_identity', [
                'source' => 'settings_admin',
                'reply_to_count' => count((array) ($values['reply_to'] ?? [])),
                'bcc_count' => count((array) ($values['bcc'] ?? [])),
                'force_from_email' => ! empty($values['force_from_email']),
                'force_from_name' => ! empty($values['force_from_name']),
                'force_reply_to' => ! empty($values['force_reply_to']),
                'force_bcc' => ! empty($values['force_bcc']),
            ]);
            $this->redirect('saved');
        } catch (InvalidArgumentException $e) {
            $this->redirect('invalid', $e->getMessage());
        }
    }

    public function render(): void
    {
        $status = isset($_GET['onesmtp_settings_status']) ? sanitize_text_field(wp_unslash((string) $_GET['onesmtp_settings_status'])) : '';
        $message = isset($_GET['onesmtp_settings_message']) ? sanitize_text_field(wp_unslash((string) $_GET['onesmtp_settings_message'])) : '';

        $identity = $this->senderIdentity->get();
        $values = $identity->toArray();

        $alerts = $this->failureAlerts->get();
        $alertValues = $alerts->toArray();

        $weeklySummary = $this->weeklySummary->get();
        $weeklyValues = $weeklySummary->toArray();
        echo '<div class="onesmtp-settings-shell">';
        $this->renderStatusNotice($status, $message);
        echo '<div data-onesmtp-component="settings-navigation"></div>';
        echo '<div class="onesmtp-settings-group" data-onesmtp-settings-group="general"><div class="onesmtp-settings-grid">';

        $this->renderPanel(
            __('Sender identity', 'onesmtp'),
            __('Configure default sender headers for outgoing WordPress mail. Existing headers are preserved unless the matching force option is enabled.', 'onesmtp'),
            true,
            function () use ($values): void {
        echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-settings#onesmtp-settings')) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_sender_identity">';
                wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
                echo '<table class="form-table" role="presentation"><tbody>';
                $this->renderInput('from_email', __('From Email', 'onesmtp'), $values['from_email'], 'email');
                $this->renderInput('from_name', __('From Name', 'onesmtp'), $values['from_name']);
                $this->renderTextarea('reply_to', __('Reply-To', 'onesmtp'), implode("\n", $values['reply_to']));
                $this->renderTextarea('bcc', __('BCC', 'onesmtp'), implode("\n", $values['bcc']));
                echo '</tbody></table>';
                echo '<fieldset class="onesmtp-settings-fieldset"><legend>' . esc_html__('Force settings', 'onesmtp') . '</legend>';
                $this->renderCheckbox('force_from_email', __('Force From Email when a message already has a From header.', 'onesmtp'), (bool) $values['force_from_email']);
                $this->renderCheckbox('force_from_name', __('Force From Name when a message already has a From header.', 'onesmtp'), (bool) $values['force_from_name']);
                $this->renderCheckbox('force_reply_to', __('Force Reply-To when a message already has Reply-To.', 'onesmtp'), (bool) $values['force_reply_to']);
                $this->renderCheckbox('force_bcc', __('Force BCC when a message already has BCC.', 'onesmtp'), (bool) $values['force_bcc']);
                echo '</fieldset>';
                $this->renderActionFooter(__('Save sender identity', 'onesmtp'));
                echo '</form>';
            }
        );

        echo '</div></div><div class="onesmtp-settings-group" data-onesmtp-settings-group="notifications" hidden><div class="onesmtp-settings-grid">';

        $this->renderPanel(
            __('Failure alerts', 'onesmtp'),
            __('Send privacy-safe alerts for terminal delivery failures. Alert payloads include IDs, hashes, status, provider summary, reason, and category only.', 'onesmtp'),
            true,
            function () use ($alerts, $alertValues): void {
                if (! $alerts->hasEnabledChannel()) {
                    $this->renderInlineNotice('info', __('Failure alerts are disabled until an email recipient or HTTPS webhook is enabled.', 'onesmtp'));
                }

                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp#onesmtp-settings')) . '">';
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
                $this->renderActionFooter(__('Save failure alerts', 'onesmtp'));
                echo '</form>';
            }
        );

        $this->renderPanel(
            __('Weekly delivery summary', 'onesmtp'),
            __('Send a weekly privacy-safe delivery health summary with aggregate sent, failed, retried, pending, and failover counts. Message bodies, raw recipients, headers, secrets, attachment paths, and diagnostic payload JSON are never included.', 'onesmtp'),
            false,
            function () use ($weeklySummary, $weeklyValues): void {
                if (! $weeklySummary->isEnabled()) {
                    $this->renderInlineNotice('info', __('Weekly delivery summaries are disabled until the summary email is enabled and at least one recipient is configured.', 'onesmtp'));
                }

                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url(admin_url('options-general.php?page=onesmtp#onesmtp-settings')) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_weekly_summary">';
                wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
                echo '<table class="form-table" role="presentation"><tbody>';
                echo '<tr><th scope="row">' . esc_html__('Summary email', 'onesmtp') . '</th><td>';
                $this->renderCheckbox('weekly_summary_enabled', __('Enable weekly delivery summary email.', 'onesmtp'), ! empty($weeklyValues['enabled']));
                echo '</td></tr>';
                $this->renderTextarea('weekly_summary_email_recipients', __('Summary recipients', 'onesmtp'), implode("\n", (array) ($weeklyValues['email_recipients'] ?? [])));
                echo '</tbody></table>';
                $this->renderActionFooter(__('Save weekly delivery summary', 'onesmtp'));
                echo '</form>';
            }
        );

        echo '</div></div>';
        echo '</div>';
    }

    /**
     * Render delivery controls and data-transfer operations intended for
     * experienced administrators. This is kept separate from daily settings
     * so sender identity and notifications remain easy to scan.
     */
    public function renderAdvanced(): void
    {
        $status = isset($_GET['onesmtp_settings_status']) ? sanitize_text_field(wp_unslash((string) $_GET['onesmtp_settings_status'])) : '';
        $message = isset($_GET['onesmtp_settings_message']) ? sanitize_text_field(wp_unslash((string) $_GET['onesmtp_settings_message'])) : '';
        $limits = $this->rateLimits->get()->toArray();
        $backgroundSending = $this->backgroundSending->get();
        $attachmentLogging = $this->attachmentLogging->get();
        $simulationMode = $this->simulationMode->get();
        $alerts = $this->failureAlerts->get();
        $alertValues = $alerts->toArray();
        $actionUrl = admin_url('options-general.php?page=onesmtp&tab=onesmtp-advanced#onesmtp-advanced');

        echo '<div class="onesmtp-settings-shell onesmtp-advanced-settings-shell">';
        $this->renderStatusNotice($status, $message);
        echo '<div class="onesmtp-settings-grid">';

        $this->renderPanel(
            __('Advanced alert routing', 'onesmtp'),
            __('Escalate repeated terminal failures to multiple email and HTTPS webhook destinations.', 'onesmtp'),
            true,
            function () use ($alerts, $alertValues, $actionUrl): void {
                if (! $this->featureGate->isEnabled(FeatureGate::ADVANCED_ALERTS)) {
                    $this->renderInlineNotice('info', __('Advanced alert routing is available with an enabled Pro entitlement. Existing core failure alerts remain unchanged.', 'onesmtp'));
                    return;
                }

                if (! $alerts->isAdvancedEnabled()) {
                    $this->renderInlineNotice('info', __('Advanced alert routing is disabled until at least one destination is configured.', 'onesmtp'));
                }

                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_advanced_alerts">';
                echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
                echo '<table class="form-table" role="presentation"><tbody>';
                $this->renderCheckbox('failure_alert_advanced_enabled', __('Enable Pro advanced alert escalation.', 'onesmtp'), ! empty($alertValues['advanced_enabled']));
                $destinations = [];
                foreach ((array) ($alertValues['advanced_destinations'] ?? []) as $destination) {
                    if (! is_array($destination)) {
                        continue;
                    }
                    $destinations[] = (string) ($destination['channel'] ?? '') . ':' . (string) ($destination['target'] ?? '');
                }
                $this->renderTextarea('failure_alert_advanced_destinations', __('Escalation destinations', 'onesmtp'), implode("\n", $destinations), 20480);
                echo '<tr><th scope="row"></th><td><p class="description">' . esc_html__('Use one email:address@example.test or webhook:https://hooks.example.test/path per line. Destinations are validated server-side and never written to alert or audit context.', 'onesmtp') . '</p></td></tr>';
                $this->renderNumberInput('failure_alert_escalation_failure_threshold', __('Repeated failure threshold', 'onesmtp'), (int) ($alertValues['escalation_failure_threshold'] ?? 3));
                echo '<tr><th scope="row"></th><td><p class="description">' . esc_html__('Escalation occurs when a production terminal-failure event reaches the configured attempt threshold. Raw message content is never inspected or sent.', 'onesmtp') . '</p></td></tr>';
                echo '</tbody></table>';
                $this->renderActionFooter(__('Save advanced alert routing', 'onesmtp'));
                echo '</form>';
            }
        );

        $this->renderPanel(
            __('Simulation mode', 'onesmtp'),
            __('For staging and development only. Aculect Mail captures outgoing messages in the email log as Simulated and never contacts a provider or reports them as delivered.', 'onesmtp'),
            false,
            function () use ($simulationMode, $actionUrl): void {
                if (! $this->deliveryOwnership->canAculectDeliver()) {
                    $this->renderInlineNotice('error', __('SureMail currently owns live WordPress delivery. Aculect Mail simulation mode cannot guarantee that messages are captured instead of sent until delivery ownership is migrated.', 'onesmtp'));
                }
                if ($simulationMode->isEnabled()) {
                    $this->renderInlineNotice('warning', __('Simulation mode is active. WordPress mail calls return successfully, but no email is sent.', 'onesmtp'));
                }
                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_simulation_mode">';
                echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
                echo '<fieldset class="onesmtp-settings-fieldset">';
                $this->renderCheckbox('simulation_mode_enabled', __('Enable simulation mode on this site.', 'onesmtp'), $simulationMode->isEnabled());
                echo '</fieldset>';
                $this->renderActionFooter(__('Save simulation mode', 'onesmtp'));
                echo '</form>';
            }
        );

        $this->renderPanel(
            __('Delivery rate limits', 'onesmtp'),
            __('Set optional site-wide delivery caps. When a cap is exhausted, Aculect Mail defers queued mail until capacity is available. Use 0 to disable a limit.', 'onesmtp'),
            false,
            function () use ($limits, $actionUrl): void {
                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_rate_limits">';
                echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
                echo '<table class="form-table" role="presentation"><tbody>';
                $this->renderNumberInput('rate_limit_per_minute', __('Per-minute limit', 'onesmtp'), (int) ($limits['per_minute'] ?? 0));
                $this->renderNumberInput('rate_limit_per_hour', __('Hourly limit', 'onesmtp'), (int) ($limits['per_hour'] ?? 0));
                $this->renderNumberInput('rate_limit_per_day', __('Daily limit', 'onesmtp'), (int) ($limits['per_day'] ?? 0));
                echo '</tbody></table>';
                $this->renderActionFooter(__('Save delivery rate limits', 'onesmtp'));
                echo '</form>';
            }
        );

        $this->renderPanel(
            __('Background sending', 'onesmtp'),
            __('Queue normal WordPress mail for asynchronous delivery so user-facing requests are not held by provider latency. Provider test emails and manual resends continue to run synchronously.', 'onesmtp'),
            false,
            function () use ($backgroundSending, $actionUrl): void {
                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_background_sending">';
                echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
                echo '<fieldset class="onesmtp-settings-fieldset">';
                $this->renderCheckbox('background_sending_enabled', __('Enable background sending for normal mail.', 'onesmtp'), $backgroundSending->isEnabled());
                echo '</fieldset>';
                $this->renderActionFooter(__('Save background sending', 'onesmtp'));
                echo '</form>';
            }
        );

        $this->renderPanel(
            __('Attachment logging', 'onesmtp'),
            __('When enabled, Aculect Mail stores attachment metadata only: count, safe filename, extension, and file size when available. File contents and raw server paths are not copied into logs.', 'onesmtp'),
            false,
            function () use ($attachmentLogging, $actionUrl): void {
                if (! $attachmentLogging->isEnabled()) {
                    $this->renderInlineNotice('info', __('Attachment logging is off. Aculect Mail removes raw attachment paths from stored log payloads by default.', 'onesmtp'));
                }

                echo '<p class="description">';
                echo esc_html(
                    sprintf(
                        /* translators: %d: log retention days. */
                        __('Attachment metadata is deleted with the parent email log according to the current %d-day log retention policy. Messages with file attachments may not preserve attachments for background retries or manual resend unless the source can provide them again.', 'onesmtp'),
                        RetentionPolicy::getLogRetentionDays()
                    )
                );
                echo '</p>';
                echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                echo '<input type="hidden" name="onesmtp_settings_action" value="save_attachment_logging">';
                echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
                echo '<fieldset class="onesmtp-settings-fieldset">';
                $this->renderCheckbox('attachment_logging_enabled', __('Enable privacy-safe attachment metadata in email logs.', 'onesmtp'), $attachmentLogging->isEnabled());
                echo '</fieldset>';
                $this->renderActionFooter(__('Save attachment logging', 'onesmtp'));
                echo '</form>';
            }
        );

        $this->renderPanel(
            __('Settings import/export', 'onesmtp'),
            __('Move safe Aculect Mail configuration between environments without exposing secrets, credentials, raw recipients, headers, or payload data.', 'onesmtp'),
            true,
            function (): void {
                $this->renderImportExport('onesmtp-advanced');
            }
        );

        echo '</div>';
        echo '</div>';
    }

    private function renderInput(string $name, string $label, mixed $value, string $type = 'text', string $class = 'regular-text', int $maxlength = 0): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="' . esc_attr($type) . '" class="' . esc_attr($class) . '" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '"' . ($maxlength > 0 ? ' maxlength="' . esc_attr((string) $maxlength) . '"' : '') . '>';
        echo '</td></tr>';
    }

    private function renderTextarea(string $name, string $label, string $value, int $maxlength = 0): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<textarea class="large-text code" rows="3" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '"' . ($maxlength > 0 ? ' maxlength="' . esc_attr((string) $maxlength) . '"' : '') . '>' . esc_html($value) . '</textarea>';
        echo '<p class="description">' . esc_html__('Enter one email address per line or separate addresses with commas.', 'onesmtp') . '</p>';
        echo '</td></tr>';
    }

    private function renderCheckbox(string $name, string $label, bool $checked): void
    {
        echo '<p class="onesmtp-setting-checkbox"><label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . ($checked ? ' checked="checked"' : '') . '> ' . esc_html($label) . '</label></p>';
    }

    private function renderNumberInput(string $name, string $label, int $value): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="number" min="0" max="1000000" step="1" class="small-text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) max(0, $value)) . '">';
        echo '</td></tr>';
    }

    private function renderImportExport(string $returnTab): void
    {
        $returnUrl = admin_url('options-general.php?page=onesmtp&tab=' . rawurlencode($returnTab) . '#' . $returnTab);
        $downloadUrl = add_query_arg(
            [
                'page' => 'onesmtp',
                'onesmtp_settings_action' => self::EXPORT_ACTION,
                self::EXPORT_NONCE_NAME => wp_create_nonce(self::EXPORT_ACTION),
            ],
            $returnUrl
        );

        echo '<p>' . esc_html__('Download a privacy-safe JSON settings file for migration or backup. Provider secrets, credentials, tokens, passwords, API keys, webhook URLs, raw recipients, message bodies, raw headers, and payload JSON are excluded by default.', 'onesmtp') . '</p>';
        echo '<div class="onesmtp-settings-actions onesmtp-settings-actions--static">';
        echo '<a class="button button-secondary" href="' . esc_url($downloadUrl) . '">' . esc_html__('Download safe settings export', 'onesmtp') . '</a>';
        echo '</div>';

        echo '<form class="onesmtp-settings-form onesmtp-settings-import" method="post" action="' . esc_url($returnUrl) . '">';
        echo '<input type="hidden" name="onesmtp_settings_action" value="' . esc_attr(self::IMPORT_ACTION) . '">';
        echo '<input type="hidden" name="onesmtp_return_tab" value="' . esc_attr($returnTab) . '">';
        wp_nonce_field(self::IMPORT_ACTION, self::IMPORT_NONCE_NAME);
        echo '<p><label for="onesmtp-settings-import-json">' . esc_html__('Import safe settings JSON', 'onesmtp') . '</label></p>';
        echo '<textarea id="onesmtp-settings-import-json" class="large-text code" rows="10" name="onesmtp_settings_import_json" spellcheck="false"></textarea>';
        echo '<p class="description">' . esc_html__('Only supported non-secret settings are imported. Secret, credential, webhook URL, raw recipient, message body, raw header, and payload fields are ignored.', 'onesmtp') . '</p>';
        $this->renderActionFooter(__('Import safe settings', 'onesmtp'), 'secondary');
        echo '</form>';
    }

    private function renderStatusNotice(string $status, string $message): void
    {
        $noticeClass = '';
        $noticeText = '';

        if ($status === 'saved') {
            $noticeClass = 'success';
            $noticeText = __('Sender identity settings saved.', 'onesmtp');
        } elseif ($status === 'rate_limits_saved') {
            $noticeClass = 'success';
            $noticeText = __('Delivery rate limit settings saved.', 'onesmtp');
        } elseif ($status === 'failure_alerts_saved') {
            $noticeClass = 'success';
            $noticeText = __('Failure alert settings saved.', 'onesmtp');
        } elseif ($status === 'advanced_alerts_saved') {
            $noticeClass = 'success';
            $noticeText = __('Advanced alert routing settings saved.', 'onesmtp');
        } elseif ($status === 'background_sending_saved') {
            $noticeClass = 'success';
            $noticeText = __('Background sending settings saved.', 'onesmtp');
        } elseif ($status === 'simulation_mode_saved') {
            $noticeClass = 'success';
            $noticeText = __('Simulation mode settings saved.', 'onesmtp');
        } elseif ($status === 'simulation_mode_owner_conflict') {
            $noticeClass = 'error';
            $noticeText = $message !== '' ? $message : __('Simulation mode cannot be enabled while another plugin owns live delivery.', 'onesmtp');
        } elseif ($status === 'attachment_logging_saved') {
            $noticeClass = 'success';
            $noticeText = __('Attachment logging settings saved.', 'onesmtp');
        } elseif ($status === 'weekly_summary_saved') {
            $noticeClass = 'success';
            $noticeText = __('Weekly delivery summary settings saved.', 'onesmtp');
        } elseif ($status === 'imported') {
            $noticeClass = 'success';
            $noticeText = $message !== '' ? $message : __('Aculect Mail settings imported. Secrets and recipient fields were excluded.', 'onesmtp');
        } elseif ($status === 'invalid') {
            $noticeClass = 'error';
            $noticeText = $message !== '' ? $message : __('Aculect Mail settings could not be saved.', 'onesmtp');
        }

        if ($noticeClass === '' || $noticeText === '') {
            return;
        }

        echo '<div class="onesmtp-settings-notices">';
        $this->renderInlineNotice($noticeClass, $noticeText);
        echo '</div>';
    }

    /**
     * @param callable():void $content
     */
    private function renderPanel(string $title, string $description, bool $fullWidth, callable $content): void
    {
        $classes = 'onesmtp-settings-panel postbox';
        if ($fullWidth) {
            $classes .= ' onesmtp-settings-panel--full';
        }

        echo '<section class="' . esc_attr($classes) . '">';
        echo '<div class="postbox-header"><h3 class="hndle">' . esc_html($title) . '</h3></div>';
        echo '<div class="inside">';
        echo '<p class="description onesmtp-settings-panel-description">' . esc_html($description) . '</p>';
        $content();
        echo '</div>';
        echo '</section>';
    }

    private function renderInlineNotice(string $type, string $message): void
    {
        echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($message) . '</p></div>';
    }

    private function renderActionFooter(string $label, string $type = 'primary'): void
    {
        echo '<div class="onesmtp-settings-actions">';
        submit_button($label, $type, 'submit', false);
        echo '</div>';
    }

    private function handleExport(): void
    {
        if (! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to export Aculect Mail settings.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $nonce = isset($_GET[self::EXPORT_NONCE_NAME]) ? sanitize_text_field(wp_unslash((string) $_GET[self::EXPORT_NONCE_NAME])) : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, self::EXPORT_ACTION)) {
            wp_die(
                esc_html__('The Aculect Mail settings export link has expired. Refresh the page and try again.', 'onesmtp'),
                esc_html__('Aculect Mail export denied', 'onesmtp'),
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
            throw new RuntimeException('Aculect Mail settings exported.');
        }

        exit;
    }

    private function handleImport(): void
    {
        if (! Capabilities::canManage()) {
            wp_die(
                esc_html__('You do not have permission to import Aculect Mail settings.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $nonce = isset($_POST[self::IMPORT_NONCE_NAME]) ? sanitize_text_field(wp_unslash((string) $_POST[self::IMPORT_NONCE_NAME])) : '';
        if ($nonce === '' || ! wp_verify_nonce($nonce, self::IMPORT_ACTION)) {
            wp_die(
                esc_html__('The Aculect Mail settings import form has expired. Refresh the page and try again.', 'onesmtp'),
                esc_html__('Aculect Mail import denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $json = isset($_POST['onesmtp_settings_import_json']) ? wp_unslash((string) $_POST['onesmtp_settings_import_json']) : '';
        $summary = $this->transfers->importJson($json, self::IMPORT_NONCE_NAME);
        $this->auditLogger->logSettingsChange('import', array_merge($summary, ['source' => 'settings_admin']));
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

        $returnTab = isset($_POST['onesmtp_return_tab']) ? sanitize_key(wp_unslash((string) $_POST['onesmtp_return_tab'])) : '';
        $returnTarget = match ($returnTab) {
            'onesmtp-overview' => '#onesmtp-overview',
            'onesmtp-advanced' => '#onesmtp-advanced',
            default => '#onesmtp-settings',
        };
        $returnUrl = 'options-general.php?page=onesmtp' . ($returnTab !== '' ? '&tab=' . rawurlencode($returnTab) : '') . $returnTarget;
        wp_safe_redirect(add_query_arg($args, admin_url($returnUrl)));
        throw new RuntimeException('Aculect Mail settings admin redirected.');
    }

    private function isTestingRuntime(): bool
    {
        return defined('ONESMTP_TESTING') && (bool) constant('ONESMTP_TESTING');
    }
}
