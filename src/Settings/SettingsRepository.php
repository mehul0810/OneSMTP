<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

final class SettingsRepository
{
    private const OPTION_KEY = 'onesmtp_settings';

    public function getAll(): array
    {
        $value = get_option(self::OPTION_KEY, []);

        return is_array($value) ? $value : [];
    }

    public function save(array $settings): bool
    {
        // TODO: Wire to admin settings UI and nonce/capability checks.
        // TODO: Add field-level sanitization/validation.
        // TODO: Encrypt provider secrets before persistence.
        return (bool) update_option(self::OPTION_KEY, $settings, false);
    }
}
