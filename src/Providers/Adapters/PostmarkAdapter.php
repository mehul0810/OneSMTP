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
        $token = (string) $config->get('api_key', $config->get('token', ''));
        if ($token === '') {
            return new SendResult(false, 'missing_api_key', 'Postmark server token is not configured.');
        }

        $to = $this->normalizeRecipients($message['to'] ?? []);
        if ($to === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $headers = $this->normalizeHeaders($message['headers'] ?? []);
        $from = $this->extractFrom($headers);

        $payload = [
            'From' => $from['email'],
            'To' => implode(',', $to),
            'Subject' => $this->getSubject($message),
            'TextBody' => $this->getBody($message),
        ];

        $response = wp_remote_post(
            'https://api.postmarkapp.com/email',
            [
                'headers' => [
                    'X-Postmark-Server-Token' => $token,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => max(5, (int) $config->get('timeout', 30)),
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            return new SendResult(false, 'postmark_network_error', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            $messageId = is_array($body) ? (string) ($body['MessageID'] ?? '') : '';

            return new SendResult(true, 'accepted', 'Accepted by Postmark.', $messageId !== '' ? $messageId : null);
        }

        return new SendResult(false, 'postmark_api_error', (string) wp_remote_retrieve_body($response));
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        $probe = [
            'to' => [sanitize_email((string) get_option('admin_email'))],
            'subject' => '[OneSMTP] Postmark Connection Test',
            'message' => 'Connection test from OneSMTP.',
            'headers' => [],
        ];

        return $this->send($probe, $config);
    }
}

