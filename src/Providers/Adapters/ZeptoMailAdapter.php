<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class ZeptoMailAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'zeptomail';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $token = trim( (string) $config->get('api_key', $config->get('send_token', '')));
        if ($token === '') {
            return new SendResult(false, 'missing_api_key', 'ZeptoMail send mail token is not configured.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $payload = [
            'from' => [
				'address' => $envelope['from']['email'],
				'name' => $envelope['from']['name'],
			],
            'to' => array_map(static fn (string $email): array => ['email_address' => ['address' => $email]], $envelope['to']),
            'subject' => $envelope['subject'],
            $envelope['html'] !== '' ? 'htmlbody' : 'textbody' => $envelope['html'] !== '' ? $envelope['html'] : $envelope['text'],
        ];
        foreach (['cc', 'bcc'] as $field) {
            if ($envelope[ $field ] !== []) {
                $payload[ $field ] = array_map(static fn (string $email): array => ['email_address' => ['address' => $email]], $envelope[ $field ]);
            }
        }
        if ($envelope['reply_to'] !== '') {
            $payload['reply_to'] = [['address' => $envelope['reply_to']]];
        }

        return $this->postJson(
            'https://api.zeptomail.com/v1.1/email',
            ['Authorization' => 'Zoho-enczapikey ' . $token],
            $payload,
            'zeptomail',
            (int) $config->get('timeout', 30),
            static fn (array $response): ?string => isset($response['request_id']) ? (string) $response['request_id'] : null
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('ZeptoMail'), $config);
    }
}
