<?php

declare(strict_types=1);

namespace OneSMTP\Alerts;

use OneSMTP\Product\FeatureGate;
use WP_Error;

final class FailureAlertDispatcher
{
    private const TRANSIENT_PREFIX = 'onesmtp_failure_alert_';
    private const ADVANCED_TRANSIENT_PREFIX = 'onesmtp_advanced_failure_alert_';

    public function __construct(
        private ?FailureAlertSettingsRepository $settings = null,
        private ?FailureAlertPayloadBuilder $payloadBuilder = null,
        private ?FeatureGate $featureGate = null
    ) {
        $this->settings = $settings ?? new FailureAlertSettingsRepository();
        $this->payloadBuilder = $payloadBuilder ?? new FailureAlertPayloadBuilder();
        $this->featureGate = $featureGate ?? new FeatureGate();
    }

    public function handleTerminalFailure(array $context, ?int $messageId, ?int $providerId, int $eventId): void
    {
        $settings = $this->settings->get();
        $sendBasic = $settings->hasEnabledChannel();
        $sendAdvanced = $this->featureGate->isEnabled(FeatureGate::ADVANCED_ALERTS)
            && $settings->isAdvancedEnabled()
            && $settings->shouldEscalate($context);
        if (! $sendBasic && ! $sendAdvanced) {
            return;
        }

        $fingerprint = $this->fingerprint($context, $messageId, $providerId);
        $basicAllowed = $sendBasic && get_transient(self::TRANSIENT_PREFIX . $fingerprint) === false;
        $advancedAllowed = $sendAdvanced && get_transient(self::ADVANCED_TRANSIENT_PREFIX . $fingerprint) === false;
        if (! $basicAllowed && ! $advancedAllowed) {
            return;
        }

        if ($basicAllowed) {
            set_transient(self::TRANSIENT_PREFIX . $fingerprint, 1, $settings->getThrottleSeconds());
        }
        if ($advancedAllowed) {
            set_transient(self::ADVANCED_TRANSIENT_PREFIX . $fingerprint, 1, $settings->getThrottleSeconds());
        }

        $payload = $this->payloadBuilder->build($context, $messageId, $providerId, $eventId);

        if ($basicAllowed && $settings->isEmailEnabled()) {
            $this->sendEmail($settings->getEmailRecipients(), $payload);
        }

        if ($basicAllowed && $settings->isWebhookEnabled()) {
            $this->sendWebhook($settings->getWebhookUrl(), $payload);
        }

        if ($advancedAllowed) {
            $advancedPayload = $payload;
            $advancedPayload['alert_level'] = 'escalated';
            $advancedPayload['escalation'] = $this->escalationMetadata($context, $settings);
            $this->sendAdvancedDestinations($settings->getAdvancedDestinations(), $advancedPayload);
        }
    }

    private function sendEmail(array $recipients, array $payload, ?string $subject = null): void
    {
        if (! function_exists('wp_mail')) {
            return;
        }

        wp_mail(
            $recipients,
            $subject ?? __('Aculect Mail terminal failure alert', 'onesmtp'),
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

    /** @param array<int,array{channel:string,target:string}> $destinations */
    private function sendAdvancedDestinations(array $destinations, array $payload): void
    {
        foreach ($destinations as $destination) {
            $channel = (string) ($destination['channel'] ?? '');
            $target = (string) ($destination['target'] ?? '');
            if ($channel === 'email' && $target !== '') {
                $this->sendEmail([$target], $payload, __('Aculect Mail escalated failure alert', 'onesmtp'));
            } elseif ($channel === 'webhook' && $target !== '') {
                $this->sendWebhook($target, $payload);
            }
        }
    }

    /** @return array{trigger:string,message_type:string,priority:string} */
    private function escalationMetadata(array $context, FailureAlertSettings $settings): array
    {
        $attempt = max(0, (int) ($context['attempt'] ?? 0));
        $messageType = sanitize_key((string) ($context['message_type'] ?? ''));
        $priority = sanitize_key((string) ($context['priority'] ?? ''));
        $trigger = $attempt >= $settings->getEscalationFailureThreshold()
            ? 'repeated_failures'
            : 'high_priority_message_type';

        return [
            'trigger' => $trigger,
            'message_type' => $messageType,
            'priority' => $priority,
        ];
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
