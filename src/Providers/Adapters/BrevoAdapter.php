<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class BrevoAdapter extends AbstractAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'brevo';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = (string) $config->get('api_key', '');
        if ($apiKey === '') {
            return new SendResult(false, 'config_missing', 'Missing Brevo API key.');
        }

        $to = $this->normalizeRecipients($message['to'] ?? []);
        if ($to === []) {
            return new SendResult(false, 'invalid_to', 'Missing recipient for Brevo.');
        }

        $payload = [
            'sender' => [
                'email' => $this->extractFrom($message, $config),
                'name' => (string) $config->get('from_name', get_bloginfo('name')),
            ],
            'to' => array_map(static fn (string $email): array => ['email' => $email], $to),
            'subject' => (string) ($message['subject'] ?? ''),
            'htmlContent' => (string) ($message['message'] ?? ''),
        ];

        $response = wp_remote_post(
            'https://api.brevo.com/v3/smtp/email',
            [
                'headers' => [
                    'api-key' => $apiKey,
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return new SendResult(false, 'http_error', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            return new SendResult(true, 'sent', 'Mail delivered via Brevo API.');
        }

        return new SendResult(false, 'brevo_http_' . $status, (string) wp_remote_retrieve_body($response));
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        if ((string) $config->get('api_key', '') === '') {
            return new SendResult(false, 'config_missing', 'Missing Brevo API key.');
        }

        return new SendResult(true, 'ready', 'Brevo adapter configuration looks valid.');
    }
}
