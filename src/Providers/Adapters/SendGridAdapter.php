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
            return new SendResult(false, 'missing_api_key', 'SendGrid API key is not configured.');
        }

        $to = $this->normalizeRecipients($message['to'] ?? []);
        if ($to === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $headers = $this->normalizeHeaders($message['headers'] ?? []);
        $from = $this->extractFrom($headers);
        $subject = $this->getSubject($message);
        $body = $this->getBody($message);

        $payload = [
            'personalizations' => [
                [
                    'to' => array_map(static fn (string $email): array => ['email' => $email], $to),
                ],
            ],
            'from' => [
                'email' => $from['email'],
                'name' => $from['name'],
            ],
            'subject' => $subject,
            'content' => [
                [
                    'type' => 'text/plain',
                    'value' => $body,
                ],
            ],
        ];

        $response = wp_remote_post(
            'https://api.sendgrid.com/v3/mail/send',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => max(5, (int) $config->get('timeout', 30)),
                'body' => wp_json_encode($payload),
            ]
        );

        return $this->mapHttpResult($response, 'sendgrid');
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        $probe = [
            'to' => [sanitize_email((string) get_option('admin_email'))],
            'subject' => '[OneSMTP] SendGrid Connection Test',
            'message' => 'Connection test from OneSMTP.',
            'headers' => [],
        ];

        return $this->send($probe, $config);
    }

    private function mapHttpResult($response, string $provider): SendResult
    {
        if (is_wp_error($response)) {
            return new SendResult(false, $provider . '_network_error', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            return new SendResult(true, 'accepted', 'Accepted by ' . $provider . ' API.');
        }

        return new SendResult(false, $provider . '_api_error', (string) wp_remote_retrieve_body($response));
    }
}

