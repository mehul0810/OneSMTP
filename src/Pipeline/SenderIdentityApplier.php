<?php

declare(strict_types=1);

namespace OneSMTP\Pipeline;

use OneSMTP\Settings\SenderIdentity;
use OneSMTP\Settings\SenderIdentityRepository;

final class SenderIdentityApplier
{
    private const HEADER_FROM = 'From';
    private const HEADER_REPLY_TO = 'Reply-To';
    private const HEADER_BCC = 'Bcc';

    public function __construct(private ?SenderIdentityRepository $settings = null)
    {
        $this->settings = $settings ?? new SenderIdentityRepository();
    }

    public function registerHooks(): void
    {
        add_filter('wp_mail', [$this, 'apply'], 0, 1);
    }

    public function apply(array $args): array
    {
        $identity = $this->settings->get();
        if (
            $identity->getFromEmail() === ''
            && $identity->getReplyTo() === []
            && $identity->getBcc() === []
        ) {
            return $args;
        }

        $headers = $this->normalizeHeaders($args['headers'] ?? []);

        if ($identity->getFromEmail() !== '') {
            $headers = $this->applyFrom($headers, $identity);
        }

        if ($identity->getReplyTo() !== []) {
            $headers = $this->applyAddressHeader($headers, self::HEADER_REPLY_TO, $identity->getReplyTo(), $identity->shouldForceReplyTo());
        }

        if ($identity->getBcc() !== []) {
            $headers = $this->applyAddressHeader($headers, self::HEADER_BCC, $identity->getBcc(), $identity->shouldForceBcc());
        }

        $args['headers'] = $headers;

        return $args;
    }

    /**
     * @param array<int,string> $headers
     * @return array<int,string>
     */
    private function applyFrom(array $headers, SenderIdentity $identity): array
    {
        $existing = $this->findHeader($headers, self::HEADER_FROM);
        if ($existing !== null && ! $identity->shouldForceFromEmail() && ! $identity->shouldForceFromName()) {
            return $headers;
        }

        $email = $identity->getFromEmail();
        $name = $identity->getFromName();

        if ($existing !== null && ! $identity->shouldForceFromEmail()) {
            $email = $this->extractEmailFromHeader($existing) ?: $email;
        }

        if ($existing !== null && ! $identity->shouldForceFromName()) {
            $name = $this->extractNameFromHeader($existing);
        }

        return $this->setHeader($headers, self::HEADER_FROM, $this->formatMailbox($email, $name));
    }

    /**
     * @param array<int,string> $headers
     * @param array<int,string> $emails
     * @return array<int,string>
     */
    private function applyAddressHeader(array $headers, string $name, array $emails, bool $force): array
    {
        if ($this->hasHeader($headers, $name) && ! $force) {
            return $headers;
        }

        return $this->setHeader($headers, $name, implode(', ', $emails));
    }

    /**
     * @return array<int,string>
     */
    private function normalizeHeaders(mixed $headers): array
    {
        if (is_string($headers) && $headers !== '') {
            $headers = preg_split('/\r\n|\r|\n/', $headers) ?: [];
        }

        if (! is_array($headers)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map('strval', $headers),
                static fn (string $header): bool => trim($header) !== ''
            )
        );
    }

    /**
     * @param array<int,string> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        return $this->findHeader($headers, $name) !== null;
    }

    /**
     * @param array<int,string> $headers
     */
    private function findHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return $header;
            }
        }

        return null;
    }

    /**
     * @param array<int,string> $headers
     * @return array<int,string>
     */
    private function setHeader(array $headers, string $name, string $value): array
    {
        $updated = [];
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                continue;
            }

            $updated[] = $header;
        }

        $updated[] = $name . ': ' . $value;

        return $updated;
    }

    private function formatMailbox(string $email, string $name): string
    {
        if ($name === '') {
            return $email;
        }

        $safeName = str_replace(['"', "\r", "\n"], ['', '', ''], $name);

        return '"' . $safeName . '" <' . $email . '>';
    }

    private function extractEmailFromHeader(string $header): string
    {
        $value = trim(substr($header, strlen(self::HEADER_FROM) + 1));
        if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
            return sanitize_email((string) $matches[1]);
        }

        return sanitize_email($value);
    }

    private function extractNameFromHeader(string $header): string
    {
        $value = trim(substr($header, strlen(self::HEADER_FROM) + 1));
        if (preg_match('/^"([^"]+)"/', $value, $matches) === 1) {
            return sanitize_text_field((string) $matches[1]);
        }

        if (preg_match('/^([^<]+)</', $value, $matches) === 1) {
            return sanitize_text_field(trim((string) $matches[1], " \t\n\r\0\x0B\""));
        }

        return '';
    }
}
