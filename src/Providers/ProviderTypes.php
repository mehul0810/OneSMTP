<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

final class ProviderTypes
{
    public const PHP_MAIL = 'php_mail';
    public const GMAIL    = 'gmail';
    public const SENDGRID = 'sendgrid';
    public const POSTMARK = 'postmark';
    public const BREVO    = 'brevo';

    public static function all(): array
    {
        return [
            self::PHP_MAIL,
            self::GMAIL,
            self::SENDGRID,
            self::POSTMARK,
            self::BREVO,
        ];
    }

    public static function isSupported(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
