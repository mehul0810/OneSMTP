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
use OneSMTP\Repository\SuppressionRepository;
use OneSMTP\Suppression\SuppressionService;
use OneSMTP\Suppression\SuppressionSettings;
use OneSMTP\Suppression\SuppressionSettingsRepository;
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
        private ?FeatureGate $featureGate = null,
        private ?SuppressionService $suppression = null,
        private ?SuppressionSettingsRepository $suppressionSettings = null,
        private ?SuppressionRepository $suppressionRepository = null
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
        $this->suppressionSettings = $suppressionSettings ?? new SuppressionSettingsRepository();
        $this->suppressionRepository = $suppressionRepository ?? new SuppressionRepository();
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

            if ($action === 'save_retention_policy') {
                if (! $this->featureGate->isEnabled(FeatureGate::COMPLIANCE_CONTROLS)) {
                    return;
                }

                $profile = isset($_POST['retention_profile'])
                    ? sanitize_key(wp_unslash((string) $_POST['retention_profile']))
                    : '';
                $customDays = isset($_POST['retention_days'])
                    ? absint(wp_unslash((string) $_POST['retention_days']))
                    : RetentionPolicy::DEFAULT_DAYS;
                $days = RetentionPolicy::daysForProfile($profile, $customDays);
                RetentionPolicy::saveDays($days);
                $this->auditLogger->logSettingsChange('retention_policy', [
                    'source' => 'settings_admin',
                    'profile' => RetentionPolicy::profileForDays($days),
                    'retention_days' => $days,
                ]);
                $this->redirect('retention_policy_saved');
                return;
            }

            if ($action === 'save_bounce_suppression') {
                if (! $this->featureGate->isEnabled(FeatureGate::BOUNCE_SUPPRESSION) || ($this->suppression === null && isset($_POST['bounce_suppression_enabled']))) {
                    $this->redirect('invalid', __('Bounce suppression requires an enabled Pro entitlement and a site secret.', 'onesmtp'));
                    return;
                }
                $enabled = isset($_POST['bounce_suppression_enabled']);
                $this->suppressionSettings->save(new SuppressionSettings($enabled));
                $this->auditLogger->logSettingsChange('bounce_suppression', [
                    'source' => 'settings_admin',
                    'enabled' => $enabled,
                ]);
                $this->redirect('bounce_suppression_saved');
                return;
            }

            if ($action === 'remove_bounce_suppression') {
                if (! $this->featureGate->isEnabled(FeatureGate::BOUNCE_SUPPRESSION)) {
                    return;
                }
                $recipient = isset($_POST['suppression_recipient']) ? wp_unslash((string) $_POST['suppression_recipient']) : '';
                $fingerprint = $this->suppression?->fingerprintForLookup($recipient);
                $matched = $fingerprint !== null && $this->suppressionRepository->remove($fingerprint);
                $this->auditLogger->logSettingsChange('bounce_suppression_remove', ['source' => 'settings_admin', 'matched' => $matched]);
                $this->redirect('bounce_suppression_removed');
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
        $retentionDays = RetentionPolicy::DEFAULT_DAYS;
        $retentionProfile = 'standard';
        if ($this->featureGate->isEnabled(FeatureGate::COMPLIANCE_CONTROLS)) {
            $retentionDays = RetentionPolicy::getLogRetentionDays();
            $retentionProfile = RetentionPolicy::profileForDays($retentionDays);
        }
        $alerts = $this->failureAlerts->get();
        $alertValues = $alerts->toArray();
        $suppressionEnabled = $this->suppressionSettings->get()->isEnabled();
        $actionUrl = admin_url('options-general.php?page=onesmtp&tab=onesmtp-advanced#onesmtp-advanced');

        echo '<div class="onesmtp-settings-shell onesmtp-advanced-settings-shell">';
        $this->renderStatusNotice($status, $message);
        echo '<div class="onesmtp-settings-grid">';

        if ($this->featureGate->isEnabled(FeatureGate::BOUNCE_SUPPRESSION)) {
            $this->renderPanel(
                __('Bounce and complaint suppression', 'onesmtp'),
                __('Default-off site-local protection. Authenticated Mailgun hard-bounce and complaint events can block a complete message when any canonical recipient is still suppressed. Raw recipients and webhook payloads are never stored.', 'onesmtp'),
                true,
                function () use ($actionUrl, $suppressionEnabled): void {
                    if ($this->suppression === null) {
                        $this->renderInlineNotice('warning', __('Suppression is unavailable until WordPress provides a site secret.', 'onesmtp'));
                    }
                    echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                    echo '<input type="hidden" name="onesmtp_settings_action" value="save_bounce_suppression">';
                    echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                    wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
                    $this->renderCheckbox('bounce_suppression_enabled', __('Enable hard-bounce and complaint suppression on this site.', 'onesmtp'), $suppressionEnabled && $this->suppression !== null);
                    echo '<p class="description">' . esc_html__('Disabling stops new derivation and blocking; existing rows expire under the 30-day default (maximum 120 days). A match blocks the entire message across initial, queued, retry, and manual resend paths.', 'onesmtp') . '</p>';
                    $this->renderActionFooter(__('Save suppression setting', 'onesmtp'));
                    echo '</form>';
                    $this->renderSuppressionList();
                    echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                    echo '<input type="hidden" name="onesmtp_settings_action" value="remove_bounce_suppression">';
                    echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                    wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
                    echo '<label for="onesmtp-suppression-recipient">' . esc_html__('Remove by exact recipient', 'onesmtp') . '</label> ';
                    echo '<input id="onesmtp-suppression-recipient" type="email" name="suppression_recipient" class="regular-text" autocomplete="off"> ';
                    submit_button(__('Remove matching suppression', 'onesmtp'), 'secondary', 'submit', false);
                    echo '<p class="description">' . esc_html__('The address is hashed transiently for lookup and is not stored or written to audit context.', 'onesmtp') . '</p></form>';
                }
            );
        }

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

        if ($this->featureGate->isEnabled(FeatureGate::COMPLIANCE_CONTROLS)) {
            $this->renderPanel(
                __('Log retention policy', 'onesmtp'),
                __('Choose how long Aculect Mail keeps operational email records on this site. Retention is bounded to 1-120 days and pruning follows the selected policy.', 'onesmtp'),
                false,
                function () use ($retentionDays, $retentionProfile, $actionUrl): void {
                    echo '<form class="onesmtp-settings-form" method="post" action="' . esc_url($actionUrl) . '">';
                    echo '<input type="hidden" name="onesmtp_settings_action" value="save_retention_policy">';
                    echo '<input type="hidden" name="onesmtp_return_tab" value="onesmtp-advanced">';
                    wp_nonce_field(self::ACTION_NAME, self::NONCE_NAME);
                    echo '<fieldset class="onesmtp-settings-fieldset" aria-describedby="onesmtp-retention-policy-help">';
                    echo '<legend>' . esc_html__('Retention duration', 'onesmtp') . '</legend>';
                    echo '<p id="onesmtp-retention-policy-help" class="description">' . esc_html__('The current policy is site-local. Existing records are not purged immediately when you save; the scheduled pruner applies this duration on its next run.', 'onesmtp') . '</p>';
                    echo '<p><label for="onesmtp-retention-profile">' . esc_html__('Policy preset', 'onesmtp') . '</label><br>';
                    echo '<select id="onesmtp-retention-profile" name="retention_profile">';
                    foreach (RetentionPolicy::presets() as $profile => $preset) {
                        $this->renderOption($profile, $preset['label'], $retentionProfile);
                    }
                    $this->renderOption('custom', __('Custom duration (1-120 days)', 'onesmtp'), $retentionProfile);
                    echo '</select></p>';
                    echo '<p><label for="onesmtp-retention-days">' . esc_html__('Custom duration in days', 'onesmtp') . '</label><br>';
                    echo '<input type="number" class="small-text" id="onesmtp-retention-days" name="retention_days" min="1" max="120" step="1" value="' . esc_attr((string) $retentionDays) . '">';
                    echo ' <span class="description">' . esc_html__('Used when Custom duration is selected.', 'onesmtp') . '</span></p>';
                    $this->renderActionFooter(__('Save retention policy', 'onesmtp'));
                    echo '</fieldset></form>';
                }
            );
        }

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

    private function renderOption(string $value, string $label, string $selected): void
    {
        echo '<option value="' . esc_attr($value) . '"' . ($value === $selected ? ' selected="selected"' : '') . '>' . esc_html($label) . '</option>';
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
        } elseif ($status === 'retention_policy_saved') {
            $noticeClass = 'success';
            $noticeText = __('Log retention policy saved. Scheduled pruning will follow the selected duration.', 'onesmtp');
        } elseif ($status === 'bounce_suppression_saved') {
            $noticeClass = 'success';
            $noticeText = __('Bounce and complaint suppression settings saved.', 'onesmtp');
        } elseif ($status === 'bounce_suppression_removed') {
            $noticeClass = 'success';
            $noticeText = __('Any matching suppression was removed.', 'onesmtp');
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

    private function renderSuppressionList(): void
    {
        if ( ! Capabilities::canViewLogs() ) {
            $this->renderInlineNotice('warning', __('Suppression records require log-view access.', 'onesmtp'));

            return;
        }

        $rows = $this->suppressionRepository->listActive(current_time('mysql', true), 100);
        echo '<h4>' . esc_html__('Active suppression records', 'onesmtp') . '</h4>';
        $data = [];
        foreach ($rows as $row) {
            $providerLabel = ucwords(str_replace('_', ' ', (string) ($row['provider'] ?? '')));
            $data[] = [
                'id' => (int) ($row['id'] ?? 0),
                'fingerprint' => substr((string) ($row['recipient_fingerprint'] ?? ''), 0, 10),
                'domain' => (string) ($row['recipient_domain'] ?? ''),
                'reason' => (string) ($row['reason_code'] ?? ''),
                'firstSeen' => (string) ($row['first_seen'] ?? ''),
                'expires' => (string) ($row['expiry_at'] ?? ''),
                'count' => (int) ($row['occurrence_count'] ?? 0),
                'provider' => $providerLabel,
            ];
        }
        $payload = [
            'data' => $data,
            'fields' => [
                ['id' => 'fingerprint', 'type' => 'text', 'label' => __('Fingerprint', 'onesmtp'), 'enableHiding' => false],
                ['id' => 'domain', 'type' => 'text', 'label' => __('Domain', 'onesmtp')],
                ['id' => 'reason', 'type' => 'text', 'label' => __('Reason', 'onesmtp')],
                ['id' => 'firstSeen', 'type' => 'text', 'label' => __('First seen', 'onesmtp')],
                ['id' => 'expires', 'type' => 'text', 'label' => __('Expires', 'onesmtp')],
                ['id' => 'count', 'type' => 'integer', 'label' => __('Count', 'onesmtp')],
                ['id' => 'provider', 'type' => 'text', 'label' => __('Provider', 'onesmtp')],
            ],
        ];
        echo '<div class="onesmtp-dataviews-mount" data-onesmtp-dataviews="suppression-records"></div>';
        echo '<script type="application/json" data-onesmtp-dataviews-config="suppression-records">' . wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        echo '<details class="onesmtp-legacy-list"><summary>' . esc_html__('Legacy suppression table', 'onesmtp') . '</summary>';
        echo '<table class="widefat striped" aria-label="' . esc_attr__('Active suppression records', 'onesmtp') . '"><thead><tr><th>' . esc_html__('Fingerprint', 'onesmtp') . '</th><th>' . esc_html__('Domain', 'onesmtp') . '</th><th>' . esc_html__('Reason', 'onesmtp') . '</th><th>' . esc_html__('First seen', 'onesmtp') . '</th><th>' . esc_html__('Expires', 'onesmtp') . '</th><th>' . esc_html__('Count', 'onesmtp') . '</th><th>' . esc_html__('Provider', 'onesmtp') . '</th></tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="7">' . esc_html__('No suppression records are currently stored.', 'onesmtp') . '</td></tr>';
        } else {
            foreach ($data as $row) {
                echo '<tr><td><code>' . esc_html((string) $row['fingerprint']) . '</code></td><td>' . esc_html((string) $row['domain']) . '</td><td>' . esc_html((string) $row['reason']) . '</td><td>' . esc_html((string) $row['firstSeen']) . '</td><td>' . esc_html((string) $row['expires']) . '</td><td>' . esc_html((string) $row['count']) . '</td><td>' . esc_html((string) $row['provider']) . '</td></tr>';
            }
        }
        echo '</tbody></table></details>';
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
