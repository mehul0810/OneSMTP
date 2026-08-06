<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class Smtp2GoAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'smtp2go';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = trim( (string) $config->get('api_key', ''));
        if ($apiKey === '') {
            return new SendResult(false, 'missing_api_key', 'SMTP2GO API key is not configured.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $payload = [
            'api_key' => $apiKey,
            'sender' => $this->fromString($envelope['from']),
            'to' => $envelope['to'],
            'subject' => $envelope['subject'],
            'text_body' => $envelope['text'],
        ];
        if ($envelope['html'] !== '') {
            $payload['html_body'] = $envelope['html'];
        }
        foreach (['cc', 'bcc'] as $field) {
            if ($envelope[ $field ] !== []) {
                $payload[ $field ] = $envelope[ $field ];
            }
        }

        $region = strtolower(trim( (string) $config->get('region', 'global')));
        $base = match ($region) {
            'us' => 'https://us-api.smtp2go.com',
            'eu' => 'https://eu-api.smtp2go.com',
            'au' => 'https://au-api.smtp2go.com',
            default => 'https://api.smtp2go.com',
        };

        return $this->postJson(
            $base . '/v3/email/send',
            ['X-Smtp2go-Api-Key' => $apiKey],
            $payload,
            'smtp2go',
            (int) $config->get('timeout', 30),
            static fn (array $response): ?string => isset($response['data']['email_id']) ? (string) $response['data']['email_id'] : null
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('SMTP2GO'), $config);
    }
}
