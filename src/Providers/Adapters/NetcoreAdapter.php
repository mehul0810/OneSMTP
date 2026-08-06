<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class NetcoreAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'netcore';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = trim( (string) $config->get('api_key', ''));
        if ($apiKey === '') {
            return new SendResult(false, 'missing_api_key', 'Netcore API key is not configured.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $personalization = ['to' => $this->recipients($envelope['to'])];
        foreach (['cc', 'bcc'] as $field) {
            if ($envelope[ $field ] !== []) {
                $personalization[ $field ] = $this->recipients($envelope[ $field ]);
            }
        }
        $payload = [
            'from' => $envelope['from'],
            'personalizations' => [$personalization],
            'subject' => $envelope['subject'],
            'content' => [
                [
                    'type' => $envelope['is_html'] ? 'html' : 'text',
                    'value' => $envelope['is_html'] ? $envelope['html'] : $envelope['text'],
                ],
            ],
        ];
        if ($envelope['reply_to'] !== '') {
            $payload['reply_to'] = ['email' => $envelope['reply_to']];
        }

        $region = strtolower( (string) $config->get('region', 'us'));
        $url = $region === 'eu'
            ? 'https://apieu.netcorecloud.net/v5.1/mail/send'
            : 'https://emailapi.netcorecloud.net/v5/mail/send';

        return $this->postJson(
            $url,
            ['api_key' => $apiKey],
            $payload,
            'netcore',
            (int) $config->get('timeout', 30),
            static fn (array $response): ?string => isset($response['data']['message_id']) ? (string) $response['data']['message_id'] : null
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('Netcore'), $config);
    }

    /** @param array<int,string> $addresses */
    private function recipients(array $addresses): array
    {
        return array_map(static fn (string $email): array => ['email' => $email], $addresses);
    }
}
