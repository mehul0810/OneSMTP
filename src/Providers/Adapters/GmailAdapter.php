<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class GmailAdapter extends SmtpAdapter
{
    public function getSlug(): string
    {
        return 'gmail';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $merged = new ProviderConfig(
            array_merge(
                [
                    'host' => 'smtp.gmail.com',
                    'port' => 587,
                    'encryption' => 'tls',
                    'auth' => true,
                ],
                $config->all()
            )
        );

        return parent::send($message, $merged);
    }
}

