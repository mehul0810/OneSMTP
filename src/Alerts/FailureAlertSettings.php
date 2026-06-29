<?php

declare(strict_types=1);

namespace OneSMTP\Alerts;

use InvalidArgumentException;

final class FailureAlertSettings
{
    private const DEFAULT_THROTTLE_SECONDS = 900;
    private const MAX_THROTTLE_SECONDS = 86400;
    private const MAX_WEBHOOK_URL_LENGTH = 2048;

    /**
     * @param array<int,string> $emailRecipients
     */
    public function __construct(
        private bool $emailEnabled = false,
        private array $emailRecipients = [],
        private bool $webhookEnabled = false,
        private string $webhookUrl = '',
        private int $throttleSeconds = self::DEFAULT_THROTTLE_SECONDS
    ) {
        $this->emailRecipients = self::normalizeEmailList($emailRecipients);
        $this->webhookUrl = self::normalizeWebhookUrl($webhookUrl);
        $this->throttleSeconds = self::normalizeThrottle($throttleSeconds);
    }

    public static function fromArray(array $settings): self
    {
        return new self(
            ! empty($settings['email_enabled']),
            self::listFromValue($settings['email_recipients'] ?? []),
            ! empty($settings['webhook_enabled']),
            isset($settings['webhook_url']) ? (string) $settings['webhook_url'] : '',
            isset($settings['throttle_seconds']) ? (int) $settings['throttle_seconds'] : self::DEFAULT_THROTTLE_SECONDS
        );
    }

    public function toArray(): array
    {
        return [
            'email_enabled' => $this->emailEnabled,
            'email_recipients' => $this->emailRecipients,
            'webhook_enabled' => $this->webhookEnabled,
            'webhook_url' => $this->webhookUrl,
            'throttle_seconds' => $this->throttleSeconds,
        ];
    }

    public function isEmailEnabled(): bool
    {
        return $this->emailEnabled && $this->emailRecipients !== [];
    }

    /**
     * @return array<int,string>
     */
    public function getEmailRecipients(): array
    {
        return $this->emailRecipients;
    }

    public function isWebhookEnabled(): bool
    {
        return $this->webhookEnabled && $this->webhookUrl !== '';
    }

    public function getWebhookUrl(): string
    {
        return $this->webhookUrl;
    }

    public function getThrottleSeconds(): int
    {
        return $this->throttleSeconds;
    }

    public function hasEnabledChannel(): bool
    {
        return $this->isEmailEnabled() || $this->isWebhookEnabled();
    }

    /**
     * @return array<int,string>
     */
    private static function listFromValue(mixed $value): array
    {
        if (is_string($value)) {
            return preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_map('strval', $value);
    }

    /**
     * @param array<int,string> $emails
     * @return array<int,string>
     */
    private static function normalizeEmailList(array $emails): array
    {
        $normalized = [];

        foreach ($emails as $email) {
            $email = sanitize_email($email);
            if ($email === '') {
                continue;
            }

            if (! self::isValidEmail($email)) {
                throw new InvalidArgumentException('Failure alert recipients must be valid email addresses.');
            }

            $normalized[] = $email;
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeWebhookUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (strlen($url) > self::MAX_WEBHOOK_URL_LENGTH) {
            throw new InvalidArgumentException('Failure alert webhook URL is too long.');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('Failure alert webhook URL must be a valid HTTPS URL.');
        }

        return $url;
    }

    private static function normalizeThrottle(int $seconds): int
    {
        if ($seconds <= 0) {
            return self::DEFAULT_THROTTLE_SECONDS;
        }

        return min($seconds, self::MAX_THROTTLE_SECONDS);
    }

    private static function isValidEmail(string $email): bool
    {
        if (function_exists('is_email')) {
            return (bool) is_email($email);
        }

        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
