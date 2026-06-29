<?php

declare(strict_types=1);

namespace OneSMTP\Summary;

use InvalidArgumentException;

final class WeeklySummarySettings
{
    /**
     * @param array<int,string> $emailRecipients
     */
    public function __construct(
        private bool $enabled = false,
        private array $emailRecipients = []
    ) {
        $this->emailRecipients = self::normalizeEmailList($emailRecipients);
    }

    public static function fromArray(array $settings): self
    {
        return new self(
            ! empty($settings['enabled']),
            self::listFromValue($settings['email_recipients'] ?? [])
        );
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'email_recipients' => $this->emailRecipients,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->emailRecipients !== [];
    }

    public function isConfigured(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array<int,string>
     */
    public function getEmailRecipients(): array
    {
        return $this->emailRecipients;
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
                throw new InvalidArgumentException('Weekly summary recipients must be valid email addresses.');
            }

            $normalized[] = $email;
        }

        return array_values(array_unique($normalized));
    }

    private static function isValidEmail(string $email): bool
    {
        if (function_exists('is_email')) {
            return (bool) is_email($email);
        }

        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
