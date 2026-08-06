<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class EmailitAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'emailit';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = trim( (string) $config->get('api_key', ''));
        if ($apiKey === '') {
            return new SendResult(false, 'missing_api_key', 'Emailit API key is not configured.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $payload = [
            'from' => $this->fromString($envelope['from']),
            'to' => $envelope['to'],
            'subject' => $envelope['subject'],
            'text' => $envelope['text'],
        ];
        if ($envelope['is_html']) {
            $payload['html'] = $envelope['html'];
        }
        foreach (['cc', 'bcc'] as $field) {
            if ($envelope[ $field ] !== []) {
                $payload[ $field ] = $envelope[ $field ];
            }
        }
        if ($envelope['reply_to'] !== '') {
            $payload['reply_to'] = $envelope['reply_to'];
        }

        $messageUuid = $this->extractHeaderValue($this->normalizeHeaders($message['headers'] ?? []), 'X-OneSMTP-Message-ID');
        $requestHeaders = ['Authorization' => 'Bearer ' . $apiKey];
        if ($messageUuid !== '') {
            $requestHeaders['Idempotency-Key'] = preg_replace('/[^A-Za-z0-9_-]/', '', $messageUuid) ?: '';
        }

        return $this->postJson(
            'https://api.emailit.com/v2/emails',
            array_filter($requestHeaders),
            $payload,
            'emailit',
            (int) $config->get('timeout', 30),
            static fn (array $response): ?string => isset($response['id']) ? (string) $response['id'] : null
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('Emailit'), $config);
    }
}
