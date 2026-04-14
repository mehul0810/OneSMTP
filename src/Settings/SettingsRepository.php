<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

use OneSMTP\Security\SecretVault;
use OneSMTP\Security\Redactor;
use OneSMTP\Security\AdminGuard;

final class SettingsRepository
{
    private const OPTION_KEY = 'onesmtp_settings';
    private const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'pass',
        'api_key',
        'apikey',
        'secret',
        'client_secret',
        'access_token',
        'refresh_token',
        'token',
    ];

    private SecretVault $secretVault;
    private Redactor $redactor;
    private AdminGuard $adminGuard;

    public function __construct(?SecretVault $secretVault = null, ?Redactor $redactor = null, ?AdminGuard $adminGuard = null)
    {
        $this->secretVault = $secretVault ?? new SecretVault();
        $this->redactor    = $redactor ?? new Redactor();
        $this->adminGuard  = $adminGuard ?? new AdminGuard();
    }

    public function getAll(): array
    {
        $value = get_option(self::OPTION_KEY, []);

        return is_array($value) ? $value : [];
    }

    public function save(array $settings): bool
    {
        $this->adminGuard->assertManageRequest('onesmtp_save_settings', '_wpnonce');

        $protectedSettings = $this->encryptSensitiveSettings($settings);
        $safeForAudit      = $this->redactor->redactArray($protectedSettings);

        /**
         * Hook: onesmtp_settings_updating
         *
         * Redacted payload only. Never contains plain secrets.
         */
        do_action('onesmtp_settings_updating', $safeForAudit);

        $updated = (bool) update_option(self::OPTION_KEY, $protectedSettings, false);

        /**
         * Hook: onesmtp_settings_updated
         *
         * Redacted payload only. Never contains plain secrets.
         */
        do_action('onesmtp_settings_updated', $safeForAudit, $updated);

        return $updated;
    }

    private function encryptSensitiveSettings(array $settings): array
    {
        $protected = [];

        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $protected[$key] = $this->encryptSensitiveSettings($value);
                continue;
            }

            if (! is_string($value)) {
                $protected[$key] = $value;
                continue;
            }

            $protected[$key] = $this->isSensitiveKey((string) $key)
                ? $this->secretVault->encrypt($value)
                : $value;
        }

        return $protected;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower(trim($key));
        $sensitiveKeys = apply_filters('onesmtp_sensitive_setting_keys', self::DEFAULT_SENSITIVE_KEYS);

        if (! is_array($sensitiveKeys)) {
            $sensitiveKeys = self::DEFAULT_SENSITIVE_KEYS;
        }

        $normalizedKeys = array_map(
            static function ($item): string {
                return strtolower((string) $item);
            },
            $sensitiveKeys
        );

        return in_array($normalizedKey, $normalizedKeys, true);
    }
}
