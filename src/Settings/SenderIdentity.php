<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

use InvalidArgumentException;

final class SenderIdentity
{
    /**
     * @param array<int,string> $replyTo
     * @param array<int,string> $bcc
     */
    public function __construct(
        private string $fromEmail = '',
        private string $fromName = '',
        private array $replyTo = [],
        private array $bcc = [],
        private bool $forceFromEmail = false,
        private bool $forceFromName = false,
        private bool $forceReplyTo = false,
        private bool $forceBcc = false
    ) {
        $this->fromEmail = self::normalizeEmail($fromEmail, 'From Email');
        $this->fromName = sanitize_text_field($fromName);
        $this->replyTo = self::normalizeEmailList($replyTo, 'Reply-To');
        $this->bcc = self::normalizeEmailList($bcc, 'BCC');
    }

    public static function fromArray(array $settings): self
    {
        return new self(
            isset($settings['from_email']) ? (string) $settings['from_email'] : '',
            isset($settings['from_name']) ? (string) $settings['from_name'] : '',
            self::listFromValue($settings['reply_to'] ?? []),
            self::listFromValue($settings['bcc'] ?? []),
            ! empty($settings['force_from_email']),
            ! empty($settings['force_from_name']),
            ! empty($settings['force_reply_to']),
            ! empty($settings['force_bcc'])
        );
    }

    public function toArray(): array
    {
        return [
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'reply_to' => $this->replyTo,
            'bcc' => $this->bcc,
            'force_from_email' => $this->forceFromEmail,
            'force_from_name' => $this->forceFromName,
            'force_reply_to' => $this->forceReplyTo,
            'force_bcc' => $this->forceBcc,
        ];
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    /**
     * @return array<int,string>
     */
    public function getReplyTo(): array
    {
        return $this->replyTo;
    }

    /**
     * @return array<int,string>
     */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function shouldForceFromEmail(): bool
    {
        return $this->forceFromEmail;
    }

    public function shouldForceFromName(): bool
    {
        return $this->forceFromName;
    }

    public function shouldForceReplyTo(): bool
    {
        return $this->forceReplyTo;
    }

    public function shouldForceBcc(): bool
    {
        return $this->forceBcc;
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

    private static function normalizeEmail(string $email, string $label): string
    {
        $email = sanitize_email($email);
        if ($email === '') {
            return '';
        }

        if (! self::isValidEmail($email)) {
            throw new InvalidArgumentException($label . ' must be a valid email address.');
        }

        return $email;
    }

    /**
     * @param array<int,string> $emails
     * @return array<int,string>
     */
    private static function normalizeEmailList(array $emails, string $label): array
    {
        $normalized = [];

        foreach ($emails as $email) {
            $email = sanitize_email($email);
            if ($email === '') {
                continue;
            }

            if (! self::isValidEmail($email)) {
                throw new InvalidArgumentException($label . ' contains an invalid email address.');
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
