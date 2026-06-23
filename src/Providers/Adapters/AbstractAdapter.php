<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

abstract class AbstractAdapter
{
    protected function normalizeRecipients($to): array
    {
        if (is_string($to)) {
            $to = array_filter(array_map('trim', explode(',', $to)));
        }

        if (! is_array($to)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $to), static fn (string $email): bool => $email !== ''));
    }

    protected function normalizeHeaders($headers): array
    {
        if (is_string($headers) && $headers !== '') {
            $headers = preg_split('/\r\n|\r|\n/', $headers) ?: [];
        }

        if (! is_array($headers)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $headers), static fn (string $value): bool => $value !== ''));
    }

    protected function extractFrom(array $headers): array
    {
        foreach ($headers as $header) {
            if (stripos($header, 'from:') !== 0) {
                continue;
            }

            $fromLine = trim(substr($header, 5));
            if ($fromLine === '') {
                continue;
            }

            if (preg_match('/^(.*)<([^>]+)>$/', $fromLine, $matches)) {
                $name = trim(trim($matches[1]), '" ');
                $email = sanitize_email(trim($matches[2]));

                return [
                    'email' => $email !== '' ? $email : sanitize_email((string) get_option('admin_email')),
                    'name' => $name !== '' ? $name : (string) get_bloginfo('name'),
                ];
            }

            $email = sanitize_email($fromLine);
            if ($email !== '') {
                return [
                    'email' => $email,
                    'name' => (string) get_bloginfo('name'),
                ];
            }
        }

        return [
            'email' => sanitize_email((string) get_option('admin_email')),
            'name' => (string) get_bloginfo('name'),
        ];
    }

    protected function extractReplyTo(array $headers): array
    {
        return $this->extractAddressHeader($headers, 'reply-to');
    }

    protected function extractBcc(array $headers): array
    {
        return $this->extractAddressHeader($headers, 'bcc');
    }

    protected function extractFirstAddress(array $addresses): string
    {
        $first = reset($addresses);

        return is_string($first) ? $first : '';
    }

    protected function extractFirstAddressName(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') !== 0) {
                continue;
            }

            $line = trim(substr($header, strlen($name) + 1));
            if (preg_match('/^"([^"]+)"/', $line, $matches)) {
                return sanitize_text_field((string) $matches[1]);
            }

            if (preg_match('/^([^<]+)</', $line, $matches)) {
                return sanitize_text_field(trim((string) $matches[1], " \t\n\r\0\x0B\""));
            }
        }

        return '';
    }

    private function extractAddressHeader(array $headers, string $name): array
    {
        $addresses = [];

        foreach ($headers as $header) {
            if (stripos($header, $name . ':') !== 0) {
                continue;
            }

            $line = trim(substr($header, strlen($name) + 1));
            foreach (explode(',', $line) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }

                if (preg_match('/<([^>]+)>/', $part, $matches)) {
                    $part = (string) $matches[1];
                }

                $email = sanitize_email($part);
                if ($email !== '') {
                    $addresses[] = $email;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    protected function getSubject(array $message): string
    {
        return (string) ($message['subject'] ?? '');
    }

    protected function getBody(array $message): string
    {
        return (string) ($message['message'] ?? '');
    }
}
