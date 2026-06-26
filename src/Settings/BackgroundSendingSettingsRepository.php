<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

final class BackgroundSendingSettingsRepository
{
    private const KEY = 'background_sending';

    public function __construct(private ?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function get(): BackgroundSendingSettings
    {
        $settings = $this->settings->getAll();
        $background = isset($settings[self::KEY]) && is_array($settings[self::KEY]) ? $settings[self::KEY] : [];

        return BackgroundSendingSettings::fromArray($background);
    }

    public function save(BackgroundSendingSettings $background): bool
    {
        $settings = $this->settings->getAll();
        $settings[self::KEY] = $background->toArray();

        return $this->settings->save($settings, 'onesmtp_settings_nonce');
    }
}
