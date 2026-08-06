<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class ElasticEmailAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'elastic_email';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = trim( (string) $config->get('api_key', ''));
        if ($apiKey === '') {
            return new SendResult(false, 'missing_api_key', 'Elastic Email API key is not configured.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $body = [
            [
                'ContentType' => $envelope['html'] !== '' ? 'HTML' : 'PlainText',
                'Content' => $envelope['html'] !== '' ? $envelope['html'] : $envelope['text'],
            ],
        ];
        $payload = [
            'Recipients' => [
                'To' => $envelope['to'],
                'CC' => $envelope['cc'],
                'BCC' => $envelope['bcc'],
            ],
            'Content' => [
                'Body' => $body,
                'From' => $this->fromString($envelope['from']),
                'Subject' => $envelope['subject'],
            ],
        ];
        if ($envelope['reply_to'] !== '') {
            $payload['Content']['ReplyTo'] = $envelope['reply_to'];
        }

        return $this->postJson(
            'https://api.elasticemail.com/v4/emails/transactional',
            ['X-ElasticEmail-ApiKey' => $apiKey],
            $payload,
            'elastic_email',
            (int) $config->get('timeout', 30),
            static fn (array $response): ?string => isset($response['MessageID']) ? (string) $response['MessageID'] : null
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('Elastic Email'), $config);
    }
}
