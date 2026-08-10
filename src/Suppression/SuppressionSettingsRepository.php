<?php

declare(strict_types=1);

namespace OneSMTP\Suppression;

final class SuppressionSettingsRepository
{
    private const OPTION = 'onesmtp_bounce_suppression_enabled';

    public function get(): SuppressionSettings
    {
        return new SuppressionSettings((bool) get_option(self::OPTION, false));
    }

    public function save(SuppressionSettings $settings): bool
    {
        return update_option(self::OPTION, $settings->isEnabled(), false);
    }
}
