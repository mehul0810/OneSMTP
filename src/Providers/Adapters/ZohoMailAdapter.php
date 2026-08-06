<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class ZohoMailAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    public function __construct(private ?ZohoOAuthTokenProvider $tokens = null)
    {
        $this->tokens = $tokens ?? new ZohoOAuthTokenProvider();
    }

    public function getSlug(): string
    {
        return 'zoho_mail';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $accountId = trim( (string) $config->get('account_id', ''));
        if ($accountId === '') {
            return new SendResult(false, 'missing_credentials', 'Zoho Mail account ID is required.');
        }
        $accessToken = $this->tokens->accessToken($config);
        if ($accessToken instanceof SendResult) {
            return $accessToken;
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $payload = [
            'fromAddress' => $envelope['from']['email'],
            'toAddress' => implode(',', $envelope['to']),
            'subject' => $envelope['subject'],
            'content' => $envelope['is_html'] ? $envelope['html'] : $envelope['text'],
            'mailFormat' => $envelope['is_html'] ? 'html' : 'plaintext',
        ];
        if ($envelope['cc'] !== []) {
            $payload['ccAddress'] = implode(',', $envelope['cc']);
        }
        if ($envelope['bcc'] !== []) {
            $payload['bccAddress'] = implode(',', $envelope['bcc']);
        }

        $region = strtolower( (string) $config->get('region', 'com'));
        $allowedRegions = ['com', 'in', 'eu', 'com.au', 'jp', 'ca', 'com.cn'];
        if ( ! in_array($region, $allowedRegions, true)) {
            $region = 'com';
        }

        return $this->postJson(
            sprintf('https://mail.zoho.%s/api/accounts/%s/messages', $region, rawurlencode($accountId)),
            ['Authorization' => 'Zoho-oauthtoken ' . $accessToken],
            $payload,
            'zoho_mail',
            (int) $config->get('timeout', 30),
            static fn (array $response): ?string => isset($response['data']['messageId']) ? (string) $response['data']['messageId'] : null
        );
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('Zoho Mail'), $config);
    }
}
