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

    /**
     * @param array<int,string> $emailRecipients
     * @param array<int,array{channel:string,target:string}> $advancedDestinations
     */
    public function __construct(
        private bool $emailEnabled = false,
        private array $emailRecipients = [],
        private bool $webhookEnabled = false,
        private string $webhookUrl = '',
        private int $throttleSeconds = self::DEFAULT_THROTTLE_SECONDS,
        private bool $advancedEnabled = false,
        private array $advancedDestinations = [],
        private int $escalationFailureThreshold = self::DEFAULT_ESCALATION_FAILURE_THRESHOLD
    ) {
        $this->emailRecipients = self::normalizeEmailList($emailRecipients);
        $this->webhookUrl = self::normalizeWebhookUrl($webhookUrl);
        $this->throttleSeconds = self::normalizeThrottle($throttleSeconds);
        $this->advancedDestinations = self::normalizeDestinations($advancedDestinations);
        $this->escalationFailureThreshold = self::normalizeEscalationThreshold($escalationFailureThreshold);
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
            isset($settings['escalation_failure_threshold']) ? (int) $settings['escalation_failure_threshold'] : self::DEFAULT_ESCALATION_FAILURE_THRESHOLD
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

    public function shouldEscalate(array $context): bool
    {
        $attempt = max(
            0,
            (int) ($context['attempt'] ?? 0),
            (int) ($context['consecutive_failures'] ?? 0)
        );

        return $attempt >= $this->escalationFailureThreshold;
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
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
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

    /**
     * Revalidate a webhook immediately before a request. WordPress performs
     * its own safe-request validation, while this boundary also rejects any
     * private or reserved address returned by the current DNS resolution.
     *
     * @param callable(string):array<int,string>|null $resolver
     */
    public static function isSafeWebhookUrl(string $url, ?callable $resolver = null): bool
    {
        try {
            $normalized = self::normalizeWebhookUrl($url);
        } catch (InvalidArgumentException) {
            return false;
        }

        if ($normalized === '') {
            return false;
        }

        $host = self::hostFromUrl($normalized);
        if ($host === '' || self::isPrivateIp($host)) {
            return false;
        }

        if ($resolver !== null) {
            $addresses = $resolver($host);
            if (! is_array($addresses) || $addresses === []) {
                return false;
            }

            return self::allPublicAddresses($addresses);
        }

        $addresses = self::resolveHostIps($host);
        if ($addresses !== [] && ! self::allPublicAddresses($addresses)) {
            return false;
        }

        return function_exists('wp_http_validate_url') && wp_http_validate_url($normalized) !== false;
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

    private static function hostFromUrl(string $url): string
    {
        $parts = wp_parse_url($url);

        return is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
    }

    /** @return array<int,string> */
    private static function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (! function_exists('dns_get_record') || ! defined('DNS_A')) {
            return [];
        }

        $recordType = DNS_A | (defined('DNS_AAAA') ? DNS_AAAA : 0);
        $records = @dns_get_record($host, $recordType);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && is_string($record[$key])) {
                    $addresses[] = $record[$key];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /** @param array<int,string> $addresses */
    private static function allPublicAddresses(array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false || self::isPrivateIp($address)) {
                return false;
            }
        }

        return true;
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

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = array_map('intval', explode('.', $host));
            if (count($octets) !== 4) {
                return true;
            }

            [$first, $second, $third] = $octets;

            return $first === 0
                || $first === 10
                || ($first === 100 && $second >= 64 && $second <= 127)
                || $first === 127
                || ($first === 169 && $second === 254)
                || ($first === 172 && $second >= 16 && $second <= 31)
                || ($first === 192 && $second === 0 && $third === 0)
                || ($first === 192 && $second === 0 && $third === 2)
                || ($first === 192 && $second === 88 && $third === 99)
                || ($first === 192 && $second === 168)
                || ($first === 198 && $second >= 18 && $second <= 19)
                || ($first === 198 && $second === 51 && $third === 100)
                || ($first === 203 && $second === 0 && $third === 113)
                || $first >= 224;
        }

        return str_starts_with(strtolower($host), '2001:db8:');
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
