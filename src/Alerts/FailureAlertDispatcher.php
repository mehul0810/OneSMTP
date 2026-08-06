<?php

declare(strict_types=1);

namespace OneSMTP\Alerts;

use WP_Error;

final class FailureAlertDispatcher
{
    private const TRANSIENT_PREFIX = 'onesmtp_failure_alert_';

    public function __construct(
        private ?FailureAlertSettingsRepository $settings = null,
        private ?FailureAlertPayloadBuilder $payloadBuilder = null
    ) {
        $this->settings = $settings ?? new FailureAlertSettingsRepository();
        $this->payloadBuilder = $payloadBuilder ?? new FailureAlertPayloadBuilder();
    }

    public function handleTerminalFailure(array $context, ?int $messageId, ?int $providerId, int $eventId): void
    {
        $settings = $this->settings->get();
        if (! $settings->hasEnabledChannel()) {
            return;
        }

        $fingerprint = $this->fingerprint($context, $messageId, $providerId);
        $transientKey = self::TRANSIENT_PREFIX . $fingerprint;
        if (get_transient($transientKey) !== false) {
            return;
        }

        set_transient($transientKey, 1, $settings->getThrottleSeconds());

        $payload = $this->payloadBuilder->build($context, $messageId, $providerId, $eventId);

        if ($settings->isEmailEnabled()) {
            $this->sendEmail($settings->getEmailRecipients(), $payload);
        }

        if ($settings->isWebhookEnabled()) {
            $this->sendWebhook($settings->getWebhookUrl(), $payload);
        }
    }

    private function sendEmail(array $recipients, array $payload): void
    {
        if (! function_exists('wp_mail')) {
            return;
        }

        wp_mail(
            $recipients,
            __('Aculect Mail terminal failure alert', 'onesmtp'),
            (string) wp_json_encode($payload, JSON_PRETTY_PRINT),
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

    private function sendWebhook(string $url, array $payload): void
    {
        $response = wp_remote_post(
            $url,
            [
                'timeout' => 5,
                'redirection' => 0,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => (string) wp_json_encode($payload),
            ]
        );

        if ($response instanceof WP_Error) {
            return;
        }
    }

    private function fingerprint(array $context, ?int $messageId, ?int $providerId): string
    {
        return hash(
            'sha256',
            wp_json_encode(
                [
                    'message_id' => $messageId,
                    'provider_id' => $providerId,
                    'reason' => sanitize_key((string) ($context['reason'] ?? '')),
                    'category' => sanitize_key((string) ($context['failure_category'] ?? '')),
                ]
            )
        );
    }
}
