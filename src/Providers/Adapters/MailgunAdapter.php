<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\FailureClassifier;
use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class MailgunAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'mailgun';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = trim( (string) $config->get('api_key', ''));
        $domain = trim( (string) $config->get('domain', ''));
        if ($apiKey === '' || $domain === '') {
            return new SendResult(false, 'missing_credentials', 'Mailgun API key and sending domain are required.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $fields = [
            'from' => $this->fromString($envelope['from']),
            'to' => $envelope['to'],
            'subject' => $envelope['subject'],
            $envelope['html'] !== '' ? 'html' : 'text' => $envelope['html'] !== '' ? $envelope['html'] : $envelope['text'],
        ];
        foreach (['cc', 'bcc'] as $field) {
            if ($envelope[ $field ] !== []) {
                $fields[ $field ] = $envelope[ $field ];
            }
        }
        if ($envelope['reply_to'] !== '') {
            $fields['h:Reply-To'] = $envelope['reply_to'];
        }

        $region = strtolower(trim( (string) $config->get('region', 'us')));
        $base = $region === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
        $url = $base . '/v3/' . rawurlencode($domain) . '/messages';

        $multipart = $this->multipartBody($fields);
        $response = wp_remote_post(
            $url,
            [
                'headers' => [
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication requires RFC 7617 credential encoding.
                    'Authorization' => 'Basic ' . base64_encode('api:' . $apiKey),
                    'Accept' => 'application/json',
                    'Content-Type' => 'multipart/form-data; boundary=' . $multipart['boundary'],
                ],
                'timeout' => max(5, (int) $config->get('timeout', 30)),
                'body' => $multipart['body'],
            ]
        );

        if (is_wp_error($response)) {
            return new SendResult(
                false,
                'mailgun_network_error',
                sanitize_text_field($response->get_error_message()),
                null,
                FailureClassifier::classify($response->get_error_code(), $response->get_error_message())
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            $messageId = isset($decoded['id']) ? trim( (string) $decoded['id']) : null;

            return new SendResult(true, 'accepted', 'Accepted by Mailgun API.', $messageId !== '' ? $messageId : null);
        }

        $messageText = isset($decoded['message']) && is_scalar($decoded['message'])
            ? (string) $decoded['message']
            : $body;

        return new SendResult(
            false,
            'mailgun_api_error',
            substr(sanitize_text_field($messageText), 0, 500),
            null,
            FailureClassifier::classify('mailgun_api_error', $messageText, $status)
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('Mailgun'), $config);
    }

    /** @param array<string,mixed> $fields @return array{body:string,boundary:string} */
    private function multipartBody(array $fields): array
    {
        $boundary = '----AculectMail' . wp_generate_password(20, false, false);
        $body = '';
        foreach ($fields as $name => $values) {
            $values = is_array($values) ? $values : [$values];
            foreach ($values as $value) {
                $body .= '--' . $boundary . "\r\n";
                $body .= 'Content-Disposition: form-data; name="' . str_replace('"', '', (string) $name) . "\"\r\n\r\n";
                $body .= (string) $value . "\r\n";
            }
        }
        $body .= '--' . $boundary . "--\r\n";

        return [
			'body' => $body,
			'boundary' => $boundary,
		];
    }
}
