<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\SendResult;

final class ResendAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'resend';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = trim( (string) $config->get('api_key', ''));
        if ($apiKey === '') {
            return new SendResult(false, 'missing_api_key', 'Resend API key is not configured.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $payload = [
            'from' => $this->fromString($envelope['from']),
            'to' => $envelope['to'],
            'subject' => $envelope['subject'],
        ];
        $payload[ $envelope['is_html'] ? 'html' : 'text' ] = $envelope[ $envelope['is_html'] ? 'html' : 'text' ];

        foreach (['cc', 'bcc'] as $field) {
            if ($envelope[ $field ] !== []) {
                $payload[ $field ] = $envelope[ $field ];
            }
        }
        if ($envelope['reply_to'] !== '') {
            $payload['reply_to'] = $envelope['reply_to'];
        }

        $messageUuid = $this->messageUuid($message);

        return $this->postJson(
            'https://api.resend.com/emails',
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Idempotency-Key' => $messageUuid,
            ],
            $payload,
            'resend',
            (int) $config->get('timeout', 30),
            static fn (array $response): ?string => isset($response['id']) ? (string) $response['id'] : null
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('Resend'), $config);
    }

    private function messageUuid(array $message): string
    {
        $headers = $this->normalizeHeaders($message['headers'] ?? []);
        foreach ($headers as $header) {
            if (stripos($header, 'x-onesmtp-message-id:') === 0) {
                return trim(substr($header, 22));
            }
        }

        return 'onesmtp-' . hash('sha256', wp_json_encode([
            'to' => $message['to'] ?? [],
            'subject' => $message['subject'] ?? '',
            'message' => $message['message'] ?? '',
        ]));
    }
}
