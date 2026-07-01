<?php

declare(strict_types=1);

namespace OneSMTP\Audit;

use OneSMTP\Repository\EventRepository;
use OneSMTP\Security\Redactor;

final class AdminAuditLogger
{
    public function __construct(
        private ?EventRepository $events = null,
        private ?Redactor $redactor = null
    ) {
        $this->events = $events ?? new EventRepository();
        $this->redactor = $redactor ?? new Redactor();
    }

    public function logSettingsChange(string $group, array $details = []): int
    {
        return $this->events->add(
            'audit_settings_changed',
            $this->sanitizeContext(array_merge(
                [
                    'summary' => sprintf('Updated %s settings.', str_replace('_', ' ', sanitize_key($group))),
                    'object_type' => 'settings_group',
                    'object_key' => sanitize_key($group),
                ],
                $details
            ))
        );
    }

    public function logProviderChange(string $action, int $providerId, array $details = []): int
    {
        return $this->events->add(
            'audit_provider_changed',
            $this->sanitizeContext(array_merge(
                [
                    'summary' => sprintf('Provider %s.', str_replace('_', ' ', sanitize_key($action))),
                    'object_type' => 'provider',
                    'action' => sanitize_key($action),
                ],
                $details
            )),
            null,
            $providerId > 0 ? $providerId : null
        );
    }

    public function logManualResendAttempt(int $messageId, ?int $providerId, string $status, array $details = []): int
    {
        return $this->events->add(
            'audit_manual_resend',
            $this->sanitizeContext(array_merge(
                [
                    'summary' => sprintf('Manual resend %s.', str_replace('_', ' ', sanitize_key($status))),
                    'object_type' => 'message',
                    'action' => 'manual_resend',
                    'status' => sanitize_key($status),
                ],
                $details
            )),
            $messageId > 0 ? $messageId : null,
            $providerId
        );
    }

    public function logAlertAcknowledgement(string $alertType, array $details = []): int
    {
        return $this->events->add(
            'audit_alert_acknowledged',
            $this->sanitizeContext(array_merge(
                [
                    'summary' => sprintf('Acknowledged %s alert.', str_replace('_', ' ', sanitize_key($alertType))),
                    'object_type' => 'admin_alert',
                    'object_key' => sanitize_key($alertType),
                ],
                $details
            ))
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function sanitizeContext(array $context): array
    {
        return $this->sanitizeValue($this->redactor->redactArray($context));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $itemKey => $item) {
                $sanitized[$itemKey] = $this->sanitizeValue($item, is_string($itemKey) ? $itemKey : null);
            }

            return $sanitized;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            if ($key !== null && $this->isSensitiveKey($key)) {
                return '[REDACTED]';
            }

            return $this->redactor->redactText(sanitize_text_field($value), 190);
        }

        return $this->redactor->redactText(sanitize_text_field(wp_json_encode($value) ?: ''), 190);
    }

    private function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match('/pass|secret|token|api(?:_|-)?key|authorization|oauth|credential/i', $key);
    }
}
