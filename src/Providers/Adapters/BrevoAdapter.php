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
            return new SendResult(false, 'missing_api_key', 'Brevo API key is not configured.');
        }

        $to = $this->normalizeRecipients($message['to'] ?? []);
        if ($to === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $headers = $this->normalizeHeaders($message['headers'] ?? []);
        $from = $this->extractFrom($headers);

        $payload = [
            'sender' => [
                'email' => $from['email'],
                'name' => $from['name'],
            ],
            'to' => array_map(static fn (string $email): array => ['email' => $email], $to),
            'subject' => $this->getSubject($message),
            'textContent' => $this->getBody($message),
        ];

        $response = wp_remote_post(
            'https://api.brevo.com/v3/smtp/email',
            [
                'headers' => [
                    'api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => max(5, (int) $config->get('timeout', 30)),
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            return new SendResult(false, 'brevo_network_error', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            $messageId = is_array($body) ? (string) ($body['messageId'] ?? '') : '';

            return new SendResult(true, 'accepted', 'Accepted by Brevo.', $messageId !== '' ? $messageId : null);
        }

        return new SendResult(false, 'brevo_api_error', (string) wp_remote_retrieve_body($response));
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        $probe = [
            'to' => [sanitize_email((string) get_option('admin_email'))],
            'subject' => '[OneSMTP] Brevo Connection Test',
            'message' => 'Connection test from OneSMTP.',
            'headers' => [],
        ];

        return $this->send($probe, $config);
    }
}

