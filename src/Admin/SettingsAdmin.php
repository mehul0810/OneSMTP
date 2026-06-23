<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use InvalidArgumentException;
use OneSMTP\Settings\SenderIdentity;
use OneSMTP\Settings\SenderIdentityRepository;
use RuntimeException;

final class SettingsAdmin
{
    private const ACTION_NAME = 'onesmtp_save_settings';
    private const NONCE_NAME = 'onesmtp_settings_nonce';

    public function __construct(private ?SenderIdentityRepository $senderIdentity = null)
    {
        $this->senderIdentity = $senderIdentity ?? new SenderIdentityRepository();
    }

    public function handleRequest(): void
    {
        if (($GLOBALS['pagenow'] ?? '') !== 'admin.php') {
            return;
        }

        if (($_POST['onesmtp_settings_action'] ?? '') !== 'save_sender_identity') {
            return;
        }

        try {
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
        } elseif ($status === 'invalid') {
            echo '<div class="notice notice-error inline"><p>' . esc_html($message !== '' ? $message : __('Sender identity settings could not be saved.', 'onesmtp')) . '</p></div>';
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
    }

    private function renderInput(string $name, string $label, mixed $value, string $type = 'text'): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="' . esc_attr($type) . '" class="regular-text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
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
