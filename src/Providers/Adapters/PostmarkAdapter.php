<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class PostmarkAdapter extends AbstractAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'postmark';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $token = (string) $config->get('server_token', '');
        if ($token === '') {
            return new SendResult(false, 'config_missing', 'Missing Postmark server token.');
        }

        $to = $this->normalizeRecipients($message['to'] ?? []);
        if ($to === []) {
            return new SendResult(false, 'invalid_to', 'Missing recipient for Postmark.');
        }

        $payload = [
            'From' => $this->extractFrom($message, $config),
            'To' => implode(',', $to),
            'Subject' => (string) ($message['subject'] ?? ''),
            'HtmlBody' => (string) ($message['message'] ?? ''),
            'TextBody' => wp_strip_all_tags((string) ($message['message'] ?? '')),
        ];

        $response = wp_remote_post(
            'https://api.postmarkapp.com/email',
            [
                'headers' => [
                    'X-Postmark-Server-Token' => $token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return new SendResult(false, 'http_error', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($body, true);
            $messageId = is_array($decoded) && isset($decoded['MessageID']) ? (string) $decoded['MessageID'] : null;

            return new SendResult(true, 'sent', 'Mail delivered via Postmark API.', $messageId);
        }

        return new SendResult(false, 'postmark_http_' . $status, $body);
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        if ((string) $config->get('server_token', '') === '') {
            return new SendResult(false, 'config_missing', 'Missing Postmark server token.');
        }

        return new SendResult(true, 'ready', 'Postmark adapter configuration looks valid.');
    }
}
