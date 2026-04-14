<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class PhpMailAdapter extends AbstractAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'php_mail';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $to = $this->normalizeRecipients($message['to'] ?? []);
        if ($to === []) {
            return new SendResult(false, 'invalid_to', 'Missing recipient for PHP mail provider.');
        }

        $subject = (string) ($message['subject'] ?? '');
        $body    = (string) ($message['message'] ?? '');
        $headers = $message['headers'] ?? [];

        $ok = wp_mail($to, $subject, $body, $headers);

        return $ok
            ? new SendResult(true, 'sent', 'Mail delivered via wp_mail/php mail transport.')
            : new SendResult(false, 'send_failed', 'PHP mail transport failed.');
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return new SendResult(true, 'ready', 'PHP mail transport is available when wp_mail is configured.');
    }
}
