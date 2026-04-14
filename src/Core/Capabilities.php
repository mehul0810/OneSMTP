<?php

declare(strict_types=1);

namespace OneSMTP\Core;

final class Capabilities
{
    public const MANAGE_PLUGIN = 'manage_onesmtp';
    public const VIEW_LOGS     = 'view_onesmtp_logs';
    public const RESEND_EMAILS = 'resend_onesmtp_emails';

    /**
     * High-level mapping placeholder for future role assignment UI.
     */
    public static function defaultRoleCaps(): array
    {
        return [
            'administrator' => [
                self::MANAGE_PLUGIN,
                self::VIEW_LOGS,
                self::RESEND_EMAILS,
            ],
        ];
    }
}
