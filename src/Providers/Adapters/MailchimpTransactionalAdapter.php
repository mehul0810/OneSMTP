<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

/**
 * Mailchimp Transactional (formerly Mandrill) delivery adapter.
 */
final class MailchimpTransactionalAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'mailchimp_transactional';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $apiKey = trim( (string) $config->get('api_key', ''));
        if ($apiKey === '') {
            return new SendResult(false, 'missing_api_key', 'Mailchimp Transactional API key is not configured.');
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $recipients = [];
        foreach ($envelope['to'] as $email) {
            $recipients[] = [
				'email' => $email,
				'type' => 'to',
			];
        }
        foreach ($envelope['cc'] as $email) {
            $recipients[] = [
				'email' => $email,
				'type' => 'cc',
			];
        }
        foreach ($envelope['bcc'] as $email) {
            $recipients[] = [
				'email' => $email,
				'type' => 'bcc',
			];
        }

        $mail = [
            'from_email' => $envelope['from']['email'],
            'from_name' => $envelope['from']['name'],
            'to' => $recipients,
            'subject' => $envelope['subject'],
            'text' => $envelope['text'],
        ];
        if ($envelope['html'] !== '') {
            $mail['html'] = $envelope['html'];
        }
        if ($envelope['reply_to'] !== '') {
            $mail['headers'] = ['Reply-To' => $envelope['reply_to']];
        }

        return $this->postJson(
            'https://mandrillapp.com/api/1.0/messages/send.json',
            [],
            [
				'key' => $apiKey,
				'message' => $mail,
			],
            'mailchimp_transactional',
            (int) $config->get('timeout', 30),
            static function (array $response): ?string {
                $first = array_values($response)[0] ?? [];
                if ( ! is_array($first)) {
                    return null;
                }

                return isset($first['_id']) && is_scalar($first['_id']) ? (string) $first['_id'] : null;
            }
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('Mailchimp Transactional'), $config);
    }
}
