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

    /**
     * Provision default capabilities for supported roles.
     */
    public static function provisionDefaults(): void
    {
        foreach (self::defaultRoleCaps() as $roleName => $caps) {
            $role = get_role((string) $roleName);
            if (! $role) {
                continue;
            }

            foreach ($caps as $capability) {
                $role->add_cap((string) $capability);
            }
        }
    }

    /**
     * Remove plugin-specific capabilities from roles.
     */
    public static function revokeDefaults(): void
    {
        foreach (self::defaultRoleCaps() as $roleName => $caps) {
            $role = get_role((string) $roleName);
            if (! $role) {
                continue;
            }

            foreach ($caps as $capability) {
                $role->remove_cap((string) $capability);
            }
        }
    }

    public static function canManage(): bool
    {
        return current_user_can(self::MANAGE_PLUGIN) || current_user_can('manage_options');
    }

    public static function canViewLogs(): bool
    {
        return current_user_can(self::VIEW_LOGS) || current_user_can('manage_options');
    }

    public static function canResendEmails(): bool
    {
        return current_user_can(self::RESEND_EMAILS) || current_user_can('manage_options');
    }
}
