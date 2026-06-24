<?php

declare(strict_types=1);

namespace OneSMTP\Alerts;

use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\Redactor;

final class FailureAlertPayloadBuilder
{
    public function __construct(
        private ?MessageRepository $messages = null,
        private ?ProviderRepository $providers = null,
        private ?Redactor $redactor = null
    ) {
        $this->messages = $messages ?? new MessageRepository();
        $this->providers = $providers ?? new ProviderRepository();
        $this->redactor = $redactor ?? new Redactor();
    }

    public function build(array $context, ?int $messageId, ?int $providerId, int $eventId): array
    {
        $message = $messageId !== null && $messageId > 0 ? $this->messages->find($messageId) : null;
        $provider = $providerId !== null && $providerId > 0 ? $this->providers->findSafe($providerId) : null;

        return [
            'event' => 'terminal_failure',
            'event_id' => $eventId,
            'occurred_at' => gmdate('c'),
            'site' => [
                'name' => $this->redactor->redactText((string) get_bloginfo('name'), 120),
            ],
            'message' => $this->messageSummary(is_array($message) ? $message : [], $messageId),
            'provider' => $this->providerSummary(is_array($provider) ? $provider : [], $providerId),
            'failure' => [
                'attempt' => max(0, (int) ($context['attempt'] ?? 0)),
                'reason' => sanitize_key((string) ($context['reason'] ?? '')),
                'category' => sanitize_key((string) ($context['failure_category'] ?? '')),
            ],
        ];
    }

    private function messageSummary(array $message, ?int $messageId): array
    {
        $subject = isset($message['subject']) ? (string) $message['subject'] : '';

        return [
            'id' => $messageId !== null ? max(0, $messageId) : 0,
            'uuid' => isset($message['message_uuid']) ? sanitize_text_field((string) $message['message_uuid']) : '',
            'status' => isset($message['status']) ? sanitize_key((string) $message['status']) : '',
            'current_attempt' => isset($message['current_attempt']) ? max(0, (int) $message['current_attempt']) : 0,
            'max_attempts' => isset($message['max_attempts']) ? max(0, (int) $message['max_attempts']) : 0,
            'recipients_hash' => $this->safeHash((string) ($message['recipients_hash'] ?? '')),
            'body_hash' => $this->safeHash((string) ($message['body_hash'] ?? '')),
            'subject_hash' => $subject !== '' ? hash('sha256', $subject) : '',
        ];
    }

    private function providerSummary(array $provider, ?int $providerId): array
    {
        return [
            'id' => $providerId !== null ? max(0, $providerId) : 0,
            'name' => isset($provider['name']) ? $this->redactor->redactText(sanitize_text_field((string) $provider['name']), 120) : '',
            'adapter_type' => isset($provider['adapter_type']) ? sanitize_key((string) $provider['adapter_type']) : '',
            'circuit_state' => isset($provider['circuit_state']) ? sanitize_key((string) $provider['circuit_state']) : '',
        ];
    }

    private function safeHash(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '';
    }
}
