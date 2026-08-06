<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class MailjetAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'mailjet';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = trim( (string) $config->get('api_key', ''));
        $secretKey = trim( (string) $config->get('secret_key', $config->get('api_secret', '')));
        if ($apiKey === '' || $secretKey === '') {
            return new SendResult(false, 'missing_credentials', 'Mailjet API and secret keys are required.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $messagePayload = [
            'From' => [
                'Email' => $envelope['from']['email'],
                'Name' => $envelope['from']['name'],
            ],
            'To' => array_map(static fn (string $email): array => ['Email' => $email], $envelope['to']),
            'Subject' => $envelope['subject'],
            'TextPart' => $envelope['text'],
        ];
        if ($envelope['html'] !== '') {
            $messagePayload['HTMLPart'] = $envelope['html'];
        }
        if ($envelope['cc'] !== []) {
            $messagePayload['Cc'] = array_map(static fn (string $email): array => ['Email' => $email], $envelope['cc']);
        }
        if ($envelope['bcc'] !== []) {
            $messagePayload['Bcc'] = array_map(static fn (string $email): array => ['Email' => $email], $envelope['bcc']);
        }
        if ($envelope['reply_to'] !== '') {
            $messagePayload['ReplyTo'] = $envelope['reply_to'];
        }

        return $this->postJson(
            'https://api.mailjet.com/v3.1/send',
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication requires RFC 7617 credential encoding.
            ['Authorization' => 'Basic ' . base64_encode($apiKey . ':' . $secretKey)],
            ['Messages' => [$messagePayload]],
            'mailjet',
            (int) $config->get('timeout', 30),
            static function (array $response): ?string {
                $message = $response['Messages'][0] ?? [];
                if ( ! is_array($message)) {
                    return null;
                }

                return isset($message['MessageUUID'])
                    ? (string) $message['MessageUUID']
                    : (isset($message['To'][0]['MessageUUID']) ? (string) $message['To'][0]['MessageUUID'] : null);
            }
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('Mailjet'), $config);
    }
}
