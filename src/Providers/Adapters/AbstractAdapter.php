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

    protected function getSubject(array $message): string
    {
        return (string) ($message['subject'] ?? '');
    }

    protected function getBody(array $message): string
    {
        return (string) ($message['message'] ?? '');
    }
}
