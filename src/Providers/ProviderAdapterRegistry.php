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
     * @var array<string,ProviderAdapterDescriptor|mixed>
     */
    private array $descriptors = [];

    /** @var array<int,string> */
    private array $validationErrors = [];

    private bool $catalogMode = false;

    /**
     * @param array<string,ProviderAdapterInterface>|null $adapters
     * @param array<string,ProviderAdapterDescriptor>|null $descriptors
     */
    public function __construct(?array $adapters = null, ?array $descriptors = null)
    {
        $usingDefaultAdapters = $adapters === null;
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

        // A descriptor catalog is used for the built-in registry, while
        // legacy custom registries retain their historical adapter-only mode.
        // Callers can opt a custom registry into the additive contract by
        // supplying descriptors explicitly.
        $this->catalogMode = $usingDefaultAdapters || $descriptors !== null;
        $this->descriptors = $descriptors ?? ($usingDefaultAdapters
            ? ProviderAdapterCatalog::fromAdapters($this->adapters)
            : []);
        $this->validationErrors = $this->catalogMode
            ? ProviderAdapterContractValidator::validate($this->descriptors)
            : self::validateLegacyAdapters($this->adapters);
    }

    public function get(string $providerType): ?ProviderAdapterInterface
    {
        if ($this->validationErrors !== []) {
            return null;
        }

        return $this->adapters[ $providerType ] ?? null;
    }

    /**
     * @return array<string,ProviderAdapterInterface>
     */
    public function all(): array
    {
        return $this->validationErrors === [] ? $this->adapters : [];
    }

    /**
     * @return array<string,ProviderAdapterDescriptor|mixed>
     */
    public function getDescriptors(): array
    {
        return $this->descriptors;
    }

    /**
     * @return array<int,string>
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function isValid(): bool
    {
        return $this->validationErrors === [];
    }

    /**
     * Add a catalog declaration without changing the existing adapter
     * interface. Registration is committed only when the complete catalog
     * remains valid; malformed, duplicate, or unregistered declarations fail
     * closed and are not made available through get().
     */
    public function register(ProviderAdapterDescriptor $descriptor): bool
    {
        if ( ! $this->catalogMode || isset($this->descriptors[ $descriptor->getSlug() ])) {
            return false;
        }

        $descriptors = $this->descriptors;
        $descriptors[ $descriptor->getSlug() ] = $descriptor;
        $errors = ProviderAdapterContractValidator::validate($descriptors);
        if ($errors !== []) {
            return false;
        }

        $this->descriptors = $descriptors;
        $this->adapters[ $descriptor->getSlug() ] = $descriptor->getAdapter();
        $this->validationErrors = [];

        return true;
    }

    /** @param array<string,mixed> $adapters */
    private static function validateLegacyAdapters(array $adapters): array
    {
        $errors = [];
        foreach ($adapters as $registrationSlug => $adapter) {
            if ( ! $adapter instanceof ProviderAdapterInterface) {
                $errors[] = sprintf('Registration %s has no valid adapter.', (string) $registrationSlug);
                continue;
            }

            if ( (string) $registrationSlug !== $adapter->getSlug()) {
                $errors[] = sprintf(
                    'Registration key %s does not match adapter slug %s.',
                    (string) $registrationSlug,
                    $adapter->getSlug()
                );
            }
        }

        return array_values(array_unique($errors));
    }
}
