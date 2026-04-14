<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

use OneSMTP\Providers\Adapters\BrevoAdapter;
use OneSMTP\Providers\Adapters\GmailAdapter;
use OneSMTP\Providers\Adapters\PhpMailAdapter;
use OneSMTP\Providers\Adapters\PostmarkAdapter;
use OneSMTP\Providers\Adapters\SendGridAdapter;
use OneSMTP\Providers\Adapters\SmtpAdapter;

final class ProviderRegistry
{
    /**
     * @var array<string,ProviderAdapterInterface>
     */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = [
            ProviderTypes::PHP_MAIL => new PhpMailAdapter(),
            ProviderTypes::GMAIL => new GmailAdapter(),
            ProviderTypes::SENDGRID => new SendGridAdapter(),
            ProviderTypes::POSTMARK => new PostmarkAdapter(),
            ProviderTypes::BREVO => new BrevoAdapter(),
            'smtp' => new SmtpAdapter(),
        ];
    }

    public function get(string $adapterType): ?ProviderAdapterInterface
    {
        return $this->adapters[$adapterType] ?? null;
    }
}

