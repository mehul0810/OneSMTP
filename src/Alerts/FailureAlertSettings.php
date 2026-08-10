<?php

declare(strict_types=1);

namespace OneSMTP\Alerts;

use InvalidArgumentException;

final class FailureAlertSettings
{
    private const DEFAULT_THROTTLE_SECONDS = 900;
    private const MAX_THROTTLE_SECONDS = 86400;
    private const MAX_WEBHOOK_URL_LENGTH = 2048;
    private const DEFAULT_ESCALATION_FAILURE_THRESHOLD = 3;
    private const MAX_ESCALATION_FAILURE_THRESHOLD = 6;
    private const MAX_ADVANCED_DESTINATIONS = 10;
    private const MAX_MESSAGE_TYPES = 20;
    private const MAX_MESSAGE_TYPE_LENGTH = 64;

    /**
     * @param array<int,string> $emailRecipients
     * @param array<int,array{channel:string,target:string}> $advancedDestinations
     * @param array<int,string> $highPriorityMessageTypes
     */
    public function __construct(
        private bool $emailEnabled = false,
        private array $emailRecipients = [],
        private bool $webhookEnabled = false,
        private string $webhookUrl = '',
        private int $throttleSeconds = self::DEFAULT_THROTTLE_SECONDS,
        private bool $advancedEnabled = false,
        private array $advancedDestinations = [],
        private int $escalationFailureThreshold = self::DEFAULT_ESCALATION_FAILURE_THRESHOLD,
        private array $highPriorityMessageTypes = []
    ) {
        $this->emailRecipients = self::normalizeEmailList($emailRecipients);
        $this->webhookUrl = self::normalizeWebhookUrl($webhookUrl);
        $this->throttleSeconds = self::normalizeThrottle($throttleSeconds);
        $this->advancedDestinations = self::normalizeDestinations($advancedDestinations);
        $this->escalationFailureThreshold = self::normalizeEscalationThreshold($escalationFailureThreshold);
        $this->highPriorityMessageTypes = self::normalizeMessageTypes($highPriorityMessageTypes);
    }

    public static function fromArray(array $settings): self
    {
        return new self(
            ! empty($settings['email_enabled']),
            self::listFromValue($settings['email_recipients'] ?? []),
            ! empty($settings['webhook_enabled']),
            isset($settings['webhook_url']) ? (string) $settings['webhook_url'] : '',
            isset($settings['throttle_seconds']) ? (int) $settings['throttle_seconds'] : self::DEFAULT_THROTTLE_SECONDS,
            ! empty($settings['advanced_enabled']),
            self::destinationsFromValue($settings['advanced_destinations'] ?? $settings['destinations'] ?? []),
            isset($settings['escalation_failure_threshold']) ? (int) $settings['escalation_failure_threshold'] : self::DEFAULT_ESCALATION_FAILURE_THRESHOLD,
            self::messageTypesFromValue($settings['high_priority_message_types'] ?? [])
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
            'advanced_enabled' => $this->advancedEnabled,
            'advanced_destinations' => $this->advancedDestinations,
            'escalation_failure_threshold' => $this->escalationFailureThreshold,
            'high_priority_message_types' => $this->highPriorityMessageTypes,
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

    public function isAdvancedEnabled(): bool
    {
        return $this->advancedEnabled && $this->advancedDestinations !== [];
    }

    /** @return array<int,array{channel:string,target:string}> */
    public function getAdvancedDestinations(): array
    {
        return $this->advancedDestinations;
    }

    public function getEscalationFailureThreshold(): int
    {
        return $this->escalationFailureThreshold;
    }

    /** @return array<int,string> */
    public function getHighPriorityMessageTypes(): array
    {
        return $this->highPriorityMessageTypes;
    }

    public function shouldEscalate(array $context): bool
    {
        $attempt = max(0, (int) ($context['attempt'] ?? 0));
        if ($attempt >= $this->escalationFailureThreshold) {
            return true;
        }

        $messageType = sanitize_key((string) ($context['message_type'] ?? ''));
        if ($messageType !== '' && in_array($messageType, $this->highPriorityMessageTypes, true)) {
            return true;
        }

        $priority = sanitize_key((string) ($context['priority'] ?? ''));

        return ! empty($context['high_priority']) || in_array($priority, ['high', 'urgent', 'critical'], true);
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

        if (strlen($url) > self::MAX_WEBHOOK_URL_LENGTH || preg_match('/[\r\n]/', $url) === 1) {
            throw new InvalidArgumentException('Failure alert webhook URL is too long.');
        }

        $parts = wp_parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : null;
        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || ($port !== null && ($port < 1 || $port > 65535))
            || isset($parts['user'])
            || isset($parts['pass'])
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || self::isPrivateIp($host)
        ) {
            throw new InvalidArgumentException('Failure alert webhook URL must be a valid HTTPS URL.');
        }

        if (function_exists('wp_http_validate_url') && wp_http_validate_url($url) === false) {
            throw new InvalidArgumentException('Failure alert webhook URL must be a valid HTTPS URL.');
        }

        return $url;
    }

    /** @param array<int,mixed> $destinations @return array<int,array{channel:string,target:string}> */
    private static function normalizeDestinations(array $destinations): array
    {
        $normalized = [];

        foreach (array_slice($destinations, 0, self::MAX_ADVANCED_DESTINATIONS) as $destination) {
            if (is_string($destination)) {
                $destination = self::destinationFromLine($destination);
            }

            if (! is_array($destination)) {
                continue;
            }

            $channel = sanitize_key((string) ($destination['channel'] ?? $destination['type'] ?? ''));
            $target = trim((string) ($destination['target'] ?? $destination['value'] ?? ''));
            if ($channel === 'email') {
                $target = sanitize_email($target);
                if ($target === '' || ! self::isValidEmail($target)) {
                    throw new InvalidArgumentException('Advanced alert email destinations must be valid email addresses.');
                }
            } elseif ($channel === 'webhook') {
                $target = self::normalizeWebhookUrl($target);
            } else {
                continue;
            }

            $key = $channel . ':' . strtolower($target);
            $normalized[$key] = ['channel' => $channel, 'target' => $target];
        }

        return array_values($normalized);
    }

    /** @return array{channel:string,target:string}|null */
    private static function destinationFromLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        if (str_contains($line, ':')) {
            [$channel, $target] = explode(':', $line, 2);
            if (in_array(sanitize_key($channel), ['email', 'webhook'], true)) {
                return ['channel' => sanitize_key($channel), 'target' => trim($target)];
            }
        }

        return filter_var($line, FILTER_VALIDATE_EMAIL)
            ? ['channel' => 'email', 'target' => $line]
            : ['channel' => 'webhook', 'target' => $line];
    }

    /** @return array<int,mixed> */
    private static function destinationsFromValue(mixed $value): array
    {
        if (is_string($value)) {
            return array_map(
                static fn (string $line): ?array => self::destinationFromLine($line),
                preg_split('/\r\n|\r|\n/', $value) ?: []
            );
        }

        if (! is_array($value)) {
            return [];
        }

        return array_key_exists('channel', $value) || array_key_exists('type', $value)
            ? [$value]
            : array_values($value);
    }

    /** @param array<int,string> $types @return array<int,string> */
    private static function normalizeMessageTypes(array $types): array
    {
        $normalized = [];
        foreach (array_slice($types, 0, self::MAX_MESSAGE_TYPES) as $type) {
            $type = substr(sanitize_key((string) $type), 0, self::MAX_MESSAGE_TYPE_LENGTH);
            if ($type !== '') {
                $normalized[] = $type;
            }
        }

        return array_values(array_unique($normalized));
    }

    /** @return array<int,string> */
    private static function messageTypesFromValue(mixed $value): array
    {
        if (is_string($value)) {
            return preg_split('/[\r\n,]+/', $value) ?: [];
        }

        return is_array($value) ? array_map('strval', $value) : [];
    }

    private static function normalizeEscalationThreshold(int $threshold): int
    {
        if ($threshold <= 0) {
            return self::DEFAULT_ESCALATION_FAILURE_THRESHOLD;
        }

        return min($threshold, self::MAX_ESCALATION_FAILURE_THRESHOLD);
    }

    private static function isPrivateIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
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
