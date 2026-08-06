<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

use OneSMTP\Providers\Adapters\AmazonSesAdapter;
use OneSMTP\Providers\Adapters\BrevoAdapter;
use OneSMTP\Providers\Adapters\GmailAdapter;
use OneSMTP\Providers\Adapters\ElasticEmailAdapter;
use OneSMTP\Providers\Adapters\MailgunAdapter;
use OneSMTP\Providers\Adapters\MailerSendAdapter;
use OneSMTP\Providers\Adapters\MailchimpTransactionalAdapter;
use OneSMTP\Providers\Adapters\MailjetAdapter;
use OneSMTP\Providers\Adapters\PhpMailAdapter;
use OneSMTP\Providers\Adapters\PostmarkAdapter;
use OneSMTP\Providers\Adapters\ResendAdapter;
use OneSMTP\Providers\Adapters\SendGridAdapter;
use OneSMTP\Providers\Adapters\SparkPostAdapter;
use OneSMTP\Providers\Adapters\SmtpAdapter;
use OneSMTP\Providers\Adapters\Smtp2GoAdapter;
use OneSMTP\Providers\Adapters\ZeptoMailAdapter;
use OneSMTP\Providers\Adapters\ZohoMailAdapter;
use OneSMTP\Providers\Adapters\EmailitAdapter;
use OneSMTP\Providers\Adapters\NetcoreAdapter;

final class ProviderAdapterRegistry
{
    /**
     * @var array<string,ProviderAdapterInterface>
     */
    private array $adapters;

    /**
     * @param array<string,ProviderAdapterInterface>|null $adapters
     */
    public function __construct(?array $adapters = null)
    {
        $this->adapters = $adapters ?? [
            ProviderTypes::SMTP       => new SmtpAdapter(),
            ProviderTypes::AMAZON_SES => new AmazonSesAdapter(),
            ProviderTypes::PHP_MAIL   => new PhpMailAdapter(),
            ProviderTypes::GMAIL      => new GmailAdapter(),
            ProviderTypes::SENDGRID   => new SendGridAdapter(),
            ProviderTypes::POSTMARK   => new PostmarkAdapter(),
            ProviderTypes::BREVO      => new BrevoAdapter(),
            ProviderTypes::MAILGUN    => new MailgunAdapter(),
            ProviderTypes::RESEND     => new ResendAdapter(),
            ProviderTypes::MAILJET    => new MailjetAdapter(),
            ProviderTypes::SPARKPOST  => new SparkPostAdapter(),
            ProviderTypes::MAILERSEND => new MailerSendAdapter(),
            ProviderTypes::SMTP2GO    => new Smtp2GoAdapter(),
            ProviderTypes::ELASTIC_EMAIL => new ElasticEmailAdapter(),
            ProviderTypes::ZEPTOMAIL  => new ZeptoMailAdapter(),
            ProviderTypes::MAILCHIMP_TRANSACTIONAL => new MailchimpTransactionalAdapter(),
            ProviderTypes::ZOHO_MAIL => new ZohoMailAdapter(),
            ProviderTypes::EMAILIT => new EmailitAdapter(),
            ProviderTypes::NETCORE => new NetcoreAdapter(),
        ];
    }

    public function get(string $providerType): ?ProviderAdapterInterface
    {
        return $this->adapters[$providerType] ?? null;
    }
}
