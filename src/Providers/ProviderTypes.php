<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

final class ProviderTypes
{
    public const SMTP       = 'smtp';
    public const AMAZON_SES = 'amazon_ses';
    public const PHP_MAIL   = 'php_mail';
    public const GMAIL      = 'gmail';
    public const SENDGRID   = 'sendgrid';
    public const POSTMARK   = 'postmark';
    public const BREVO      = 'brevo';
    public const MAILGUN    = 'mailgun';
    public const RESEND     = 'resend';
    public const MAILJET    = 'mailjet';
    public const SPARKPOST  = 'sparkpost';
    public const MAILERSEND = 'mailersend';
    public const SMTP2GO    = 'smtp2go';
    public const ELASTIC_EMAIL = 'elastic_email';
    public const ZEPTOMAIL  = 'zeptomail';
    public const MAILCHIMP_TRANSACTIONAL = 'mailchimp_transactional';
    public const ZOHO_MAIL = 'zoho_mail';
    public const EMAILIT = 'emailit';
    public const NETCORE = 'netcore';

    public static function all(): array
    {
        return [
            self::SMTP,
            self::AMAZON_SES,
            self::PHP_MAIL,
            self::GMAIL,
            self::SENDGRID,
            self::POSTMARK,
            self::BREVO,
            self::MAILGUN,
            self::RESEND,
            self::MAILJET,
            self::SPARKPOST,
            self::MAILERSEND,
            self::SMTP2GO,
            self::ELASTIC_EMAIL,
            self::ZEPTOMAIL,
            self::MAILCHIMP_TRANSACTIONAL,
            self::ZOHO_MAIL,
            self::EMAILIT,
            self::NETCORE,
        ];
    }

    /**
     * @return array<string,array{label:string,description:string,icon:string,capabilities:array<string,bool>,notes:array<string,string>}>
     */
    public static function metadata(): array
    {
        return [
            self::SMTP => [
                'label' => __('SMTP', 'onesmtp'),
                'description' => __('Use any standard SMTP server with username and password credentials.', 'onesmtp'),
                'icon' => 'envelope',
                'capabilities' => [
                    'smtp' => true,
                    'api_delivery' => false,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => true,
                    'provider_message_id' => false,
                ],
                'notes' => [
                    'unavailable' => __('Delivery works without provider message IDs for SMTP servers.', 'onesmtp'),
                ],
            ],
            self::AMAZON_SES => [
                'label' => __('Amazon SES', 'onesmtp'),
                'description' => __('Send through the Amazon SES SMTP interface using regional SMTP credentials.', 'onesmtp'),
                'icon' => 'cloud',
                'capabilities' => [
                    'smtp' => true,
                    'api_delivery' => false,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => true,
                    'provider_message_id' => false,
                ],
                'notes' => [
                    'available' => __('Aculect Mail derives the regional SES endpoint from the selected AWS Region.', 'onesmtp'),
                ],
            ],
            self::PHP_MAIL => [
                'label' => __('PHP mail', 'onesmtp'),
                'description' => __('Use the site server mail function without external provider credentials.', 'onesmtp'),
                'icon' => 'server-stack',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => false,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => false,
                ],
                'notes' => [
                    'unavailable' => __('No external provider message ID is available from PHP mail.', 'onesmtp'),
                ],
            ],
            self::GMAIL => [
                'label' => __('Gmail', 'onesmtp'),
                'description' => __('Send through Gmail API credentials for Google-hosted mailboxes.', 'onesmtp'),
                'icon' => 'envelope',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => true,
                    'attachments' => true,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('OAuth credentials are required before delivery.', 'onesmtp'),
                ],
            ],
            self::SENDGRID => [
                'label' => __('SendGrid', 'onesmtp'),
                'description' => __('Send through the SendGrid API with an API key.', 'onesmtp'),
                'icon' => 'paper-airplane',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('API key delivery can return provider message IDs.', 'onesmtp'),
                ],
            ],
            self::POSTMARK => [
                'label' => __('Postmark', 'onesmtp'),
                'description' => __('Send through the Postmark API with a server token.', 'onesmtp'),
                'icon' => 'inbox',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('API responses can include provider message IDs.', 'onesmtp'),
                ],
            ],
            self::BREVO => [
                'label' => __('Brevo', 'onesmtp'),
                'description' => __('Send through the Brevo API with an API key.', 'onesmtp'),
                'icon' => 'bolt',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('API delivery can provide provider-side tracking identifiers.', 'onesmtp'),
                ],
            ],
            self::MAILGUN => [
                'label' => __('Mailgun', 'onesmtp'),
                'description' => __('Send through Mailgun’s regional HTTP email API.', 'onesmtp'),
                'icon' => 'mailgun',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('Choose the US or EU API region and provide a verified sending domain.', 'onesmtp'),
                ],
            ],
            self::RESEND => [
                'label' => __('Resend', 'onesmtp'),
                'description' => __('Send through the Resend email API with idempotent delivery retries.', 'onesmtp'),
                'icon' => 'resend',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('Aculect Mail sends an idempotency key derived from the message lineage.', 'onesmtp'),
                ],
            ],
            self::MAILJET => [
                'label' => __('Mailjet', 'onesmtp'),
                'description' => __('Send through Mailjet Send API v3.1 using API and secret keys.', 'onesmtp'),
                'icon' => 'mailjet',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('The API key and secret key are the same credentials used by Mailjet’s v3.1 API.', 'onesmtp'),
                ],
            ],
            self::SPARKPOST => [
                'label' => __('SparkPost', 'onesmtp'),
                'description' => __('Send through SparkPost Transmissions API with US or EU routing.', 'onesmtp'),
                'icon' => 'sparkpost',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('Select the API region that matches the SparkPost account.', 'onesmtp'),
                ],
            ],
            self::MAILERSEND => [
                'label' => __('MailerSend', 'onesmtp'),
                'description' => __('Send through the MailerSend transactional email API.', 'onesmtp'),
                'icon' => 'mailersend',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('Use an API token with permission to send from a verified domain.', 'onesmtp'),
                ],
            ],
            self::SMTP2GO => [
                'label' => __('SMTP2GO', 'onesmtp'),
                'description' => __('Send through SMTP2GO’s JSON email API with request-level IDs.', 'onesmtp'),
                'icon' => 'smtp2go',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('Use an API key from Sending > API Keys and a verified sender.', 'onesmtp'),
                ],
            ],
            self::ELASTIC_EMAIL => [
                'label' => __('Elastic Email', 'onesmtp'),
                'description' => __('Send transactional messages through Elastic Email REST API v4.', 'onesmtp'),
                'icon' => 'elastic_email',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('Create an API key with SendHttp access and verify the sender domain.', 'onesmtp'),
                ],
            ],
            self::ZEPTOMAIL => [
                'label' => __('ZeptoMail', 'onesmtp'),
                'description' => __('Send transactional email through Zoho ZeptoMail’s API.', 'onesmtp'),
                'icon' => 'zeptomail',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => false,
                ],
                'notes' => [
                    'available' => __('Use the Agent-specific send mail token from the ZeptoMail SMTP/API panel.', 'onesmtp'),
                ],
            ],
            self::MAILCHIMP_TRANSACTIONAL => [
                'label' => __('Mailchimp Transactional', 'onesmtp'),
                'description' => __('Send transactional email through Mailchimp Transactional (Mandrill).', 'onesmtp'),
                'icon' => 'mailchimp_transactional',
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => false,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('Use a Mailchimp Transactional API key and a verified sending domain.', 'onesmtp'),
                ],
            ],
            self::ZOHO_MAIL => [
                'label' => __('Zoho Mail', 'onesmtp'),
                'description' => __('Send through the Zoho Mail API using an OAuth access token.', 'onesmtp'),
                'icon' => 'zoho_mail',
                'capabilities' => [
                    'smtp' => false, 'api_delivery' => true, 'test_send' => true,
                    'oauth' => true, 'attachments' => false, 'provider_message_id' => true,
                ],
                'notes' => ['available' => __('Requires a Zoho Mail account ID and an OAuth token with message create access.', 'onesmtp')],
            ],
            self::EMAILIT => [
                'label' => __('Emailit', 'onesmtp'),
                'description' => __('Send transactional email through Emailit API v2.', 'onesmtp'),
                'icon' => 'emailit',
                'capabilities' => [
                    'smtp' => false, 'api_delivery' => true, 'test_send' => true,
                    'oauth' => false, 'attachments' => false, 'provider_message_id' => true,
                ],
                'notes' => ['available' => __('Use an Emailit API key and a verified sending domain.', 'onesmtp')],
            ],
            self::NETCORE => [
                'label' => __('Netcore', 'onesmtp'),
                'description' => __('Send transactional email through the Netcore Email API.', 'onesmtp'),
                'icon' => 'netcore',
                'capabilities' => [
                    'smtp' => false, 'api_delivery' => true, 'test_send' => true,
                    'oauth' => false, 'attachments' => false, 'provider_message_id' => true,
                ],
                'notes' => ['available' => __('Choose the US or EU API region and provide an API key.', 'onesmtp')],
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function capabilityLabels(): array
    {
        return [
            'smtp' => __('SMTP', 'onesmtp'),
            'api_delivery' => __('API delivery', 'onesmtp'),
            'test_send' => __('Test send', 'onesmtp'),
            'oauth' => __('OAuth', 'onesmtp'),
            'attachments' => __('Attachments', 'onesmtp'),
            'provider_message_id' => __('Provider message ID', 'onesmtp'),
        ];
    }

    /**
     * Return the bounded connection fields accepted by each adapter.
     *
     * Sender identity fields are intentionally owned by SenderIdentity and
     * are not part of this provider credential contract.
     *
     * @return array<string,array<string,array{type:string,required:bool,secret:bool,max_length:int,enum?:array<int,string>}>>
     */
    public static function credentialSchema(): array
    {
        $timeout = self::credentialField('integer', false, false, 4);
        $region = self::credentialField('string', false, false, 32);
        $apiKey = self::credentialField('string', true, true, 512);

        return [
            self::SMTP => [
                'host' => self::credentialField('string', true, false, 255),
                'port' => self::credentialField('integer', false, false, 5),
                'username' => self::credentialField('string', false, true, 255),
                'password' => self::credentialField('string', false, true, 512),
                'encryption' => self::credentialField('string', false, false, 16, ['tls', 'ssl']),
                'auth' => self::credentialField('boolean', false, false, 1),
                'timeout' => $timeout,
            ],
            self::AMAZON_SES => [
                'region' => self::credentialField('string', true, false, 32),
                'username' => self::credentialField('string', true, true, 255),
                'password' => self::credentialField('string', true, true, 512),
                'port' => self::credentialField('integer', false, false, 5),
                'encryption' => self::credentialField('string', false, false, 16, ['tls', 'ssl']),
                'timeout' => $timeout,
            ],
            self::GMAIL => [
                'client_id' => self::credentialField('string', true, true, 512),
                'client_secret' => self::credentialField('string', true, true, 512),
                'refresh_token' => self::credentialField('string', true, true, 2048),
            ],
            self::SENDGRID => ['api_key' => $apiKey, 'timeout' => $timeout],
            self::POSTMARK => ['api_key' => $apiKey, 'timeout' => $timeout],
            self::BREVO => ['api_key' => $apiKey, 'timeout' => $timeout],
            self::MAILGUN => [
                'api_key' => $apiKey,
                'domain' => self::credentialField('string', true, false, 255),
                'region' => $region,
                'timeout' => $timeout,
            ],
            self::RESEND => ['api_key' => $apiKey, 'timeout' => $timeout],
            self::MAILJET => [
                'api_key' => $apiKey,
                'secret_key' => self::credentialField('string', true, true, 512),
                'timeout' => $timeout,
            ],
            self::SPARKPOST => [
                'api_key' => $apiKey,
                'region' => $region,
                'timeout' => $timeout,
            ],
            self::MAILERSEND => ['api_key' => $apiKey, 'timeout' => $timeout],
            self::SMTP2GO => [
                'api_key' => $apiKey,
                'region' => self::credentialField('string', false, false, 16, ['global', 'us', 'eu', 'au']),
                'timeout' => $timeout,
            ],
            self::ELASTIC_EMAIL => ['api_key' => $apiKey, 'timeout' => $timeout],
            self::ZEPTOMAIL => ['api_key' => $apiKey, 'timeout' => $timeout],
            self::MAILCHIMP_TRANSACTIONAL => ['api_key' => $apiKey, 'timeout' => $timeout],
            self::ZOHO_MAIL => [
                'region' => self::credentialField('string', true, false, 16, ['com', 'in', 'eu', 'com.au', 'jp', 'ca', 'com.cn']),
                'account_id' => self::credentialField('string', true, false, 128),
                'client_id' => self::credentialField('string', true, true, 512),
                'client_secret' => self::credentialField('string', true, true, 512),
                'refresh_token' => self::credentialField('string', true, true, 2048),
                'timeout' => $timeout,
            ],
            self::EMAILIT => ['api_key' => $apiKey, 'timeout' => $timeout],
            self::NETCORE => [
                'api_key' => $apiKey,
                'region' => self::credentialField('string', false, false, 8, ['us', 'eu']),
                'timeout' => $timeout,
            ],
            self::PHP_MAIL => [],
        ];
    }

    public static function isSupported(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function supportsCapability(string $type, string $capability): bool
    {
        $metadata = self::metadata();

        return ! empty($metadata[$type]['capabilities'][$capability]);
    }

    /**
     * @param array<int,string> $enum
     * @return array{type:string,required:bool,secret:bool,max_length:int,enum?:array<int,string>}
     */
    private static function credentialField(string $type, bool $required, bool $secret, int $maxLength, array $enum = []): array
    {
        $field = [
            'type' => $type,
            'required' => $required,
            'secret' => $secret,
            'max_length' => $maxLength,
        ];
        if ($enum !== []) {
            $field['enum'] = $enum;
        }

        return $field;
    }
}
