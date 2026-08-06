<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class MailerSendAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'mailersend';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $token = trim( (string) $config->get('api_key', $config->get('token', '')));
        if ($token === '') {
            return new SendResult(false, 'missing_api_key', 'MailerSend API token is not configured.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $payload = [
            'from' => [
				'email' => $envelope['from']['email'],
				'name' => $envelope['from']['name'],
			],
            'to' => array_map(static fn (string $email): array => ['email' => $email], $envelope['to']),
            'subject' => $envelope['subject'],
            'text' => $envelope['text'],
        ];
        if ($envelope['html'] !== '') {
            $payload['html'] = $envelope['html'];
        }
        foreach (['cc', 'bcc'] as $field) {
            if ($envelope[ $field ] !== []) {
                $payload[ $field ] = array_map(static fn (string $email): array => ['email' => $email], $envelope[ $field ]);
            }
        }
        if ($envelope['reply_to'] !== '') {
            $payload['reply_to'] = [['email' => $envelope['reply_to']]];
        }

        return $this->postJson(
            'https://api.mailersend.com/v1/email',
            [
				'Authorization' => 'Bearer ' . $token,
				'X-Requested-With' => 'XMLHttpRequest',
			],
            $payload,
            'mailersend',
            (int) $config->get('timeout', 30)
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('MailerSend'), $config);
    }
}
