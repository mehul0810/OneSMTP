<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class SendGridAdapter extends AbstractAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'sendgrid';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = (string) $config->get('api_key', '');
        if ($apiKey === '') {
            return new SendResult(false, 'config_missing', 'Missing SendGrid API key.');
        }

        $to = $this->normalizeRecipients($message['to'] ?? []);
        if ($to === []) {
            return new SendResult(false, 'invalid_to', 'Missing recipient for SendGrid.');
        }

        $body = [
            'personalizations' => [[
                'to' => array_map(static fn (string $email): array => ['email' => $email], $to),
                'subject' => (string) ($message['subject'] ?? ''),
            ]],
            'from' => ['email' => $this->extractFrom($message, $config)],
            'content' => [[
                'type' => 'text/html',
                'value' => (string) ($message['message'] ?? ''),
            ]],
        ];

        $response = wp_remote_post(
            'https://api.sendgrid.com/v3/mail/send',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($body),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return new SendResult(false, 'http_error', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            return new SendResult(true, 'sent', 'Mail delivered via SendGrid API.');
        }

        return new SendResult(false, 'sendgrid_http_' . $status, (string) wp_remote_retrieve_body($response));
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        if ((string) $config->get('api_key', '') === '') {
            return new SendResult(false, 'config_missing', 'Missing SendGrid API key.');
        }

        return new SendResult(true, 'ready', 'SendGrid adapter configuration looks valid.');
    }
}
