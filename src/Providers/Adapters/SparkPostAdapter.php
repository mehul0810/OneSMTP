<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class SparkPostAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'sparkpost';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = trim( (string) $config->get('api_key', ''));
        if ($apiKey === '') {
            return new SendResult(false, 'missing_api_key', 'SparkPost API key is not configured.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $content = [
            'from' => $this->fromString($envelope['from']),
            'subject' => $envelope['subject'],
            'text' => $envelope['text'],
        ];
        if ($envelope['html'] !== '') {
            $content['html'] = $envelope['html'];
        }
        if ($envelope['reply_to'] !== '') {
            $content['reply_to'] = $envelope['reply_to'];
        }

        $region = strtolower(trim( (string) $config->get('region', 'us')));
        $base = $region === 'eu' ? 'https://api.eu.sparkpost.com' : 'https://api.sparkpost.com';

        return $this->postJson(
            $base . '/api/v1/transmissions',
            ['Authorization' => $apiKey],
            [
                'recipients' => array_map(static fn (string $email): array => ['address' => ['email' => $email]], array_merge($envelope['to'], $envelope['cc'], $envelope['bcc'])),
                'content' => $content,
            ],
            'sparkpost',
            (int) $config->get('timeout', 30),
            static fn (array $response): ?string => isset($response['results']['id']) ? (string) $response['results']['id'] : null
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('SparkPost'), $config);
    }
}
