<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

final class ProviderTypes
{
    public const SMTP     = 'smtp';
    public const PHP_MAIL = 'php_mail';
    public const GMAIL    = 'gmail';
    public const SENDGRID = 'sendgrid';
    public const POSTMARK = 'postmark';
    public const BREVO    = 'brevo';

    public static function all(): array
    {
        return [
            self::SMTP,
            self::PHP_MAIL,
            self::GMAIL,
            self::SENDGRID,
            self::POSTMARK,
            self::BREVO,
        ];
    }

    /**
     * @return array<string,array{label:string,description:string,capabilities:array<string,bool>,notes:array<string,string>}>
     */
    public static function metadata(): array
    {
        return [
            self::SMTP => [
                'label' => __('SMTP', 'onesmtp'),
                'description' => __('Use any standard SMTP server with username and password credentials.', 'onesmtp'),
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
            self::PHP_MAIL => [
                'label' => __('PHP mail', 'onesmtp'),
                'description' => __('Use the site server mail function without external provider credentials.', 'onesmtp'),
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => false,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => true,
                    'provider_message_id' => false,
                ],
                'notes' => [
                    'unavailable' => __('No external provider message ID is available from PHP mail.', 'onesmtp'),
                ],
            ],
            self::GMAIL => [
                'label' => __('Gmail', 'onesmtp'),
                'description' => __('Send through Gmail API credentials for Google-hosted mailboxes.', 'onesmtp'),
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
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => true,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('API key delivery can return provider message IDs.', 'onesmtp'),
                ],
            ],
            self::POSTMARK => [
                'label' => __('Postmark', 'onesmtp'),
                'description' => __('Send through the Postmark API with a server token.', 'onesmtp'),
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => true,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('API responses can include provider message IDs.', 'onesmtp'),
                ],
            ],
            self::BREVO => [
                'label' => __('Brevo', 'onesmtp'),
                'description' => __('Send through the Brevo API with an API key.', 'onesmtp'),
                'capabilities' => [
                    'smtp' => false,
                    'api_delivery' => true,
                    'test_send' => true,
                    'oauth' => false,
                    'attachments' => true,
                    'provider_message_id' => true,
                ],
                'notes' => [
                    'available' => __('API delivery can provide provider-side tracking identifiers.', 'onesmtp'),
                ],
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

    public static function isSupported(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
