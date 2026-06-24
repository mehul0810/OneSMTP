<?php

declare(strict_types=1);

namespace OneSMTP\Alerts;

use OneSMTP\Settings\SettingsRepository;

final class FailureAlertSettingsRepository
{
    private const KEY = 'failure_alerts';

    public function __construct(private ?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function get(): FailureAlertSettings
    {
        $settings = $this->settings->getAll();
        $alerts = isset($settings[self::KEY]) && is_array($settings[self::KEY]) ? $settings[self::KEY] : [];

        return FailureAlertSettings::fromArray($alerts);
    }

    public function save(FailureAlertSettings $alerts): bool
    {
        $settings = $this->settings->getAll();
        $settings[self::KEY] = $alerts->toArray();

        return $this->settings->save($settings, 'onesmtp_settings_nonce');
    }
}
