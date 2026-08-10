<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

use OneSMTP\Multisite\NetworkSettingsRepository;

final class BackgroundSendingSettingsRepository
{
    private const KEY = 'background_sending';

    public function __construct(
        private ?SettingsRepository $settings = null,
        private ?NetworkSettingsRepository $networkSettings = null
    )
    {
        $this->settings = $settings ?? new SettingsRepository();
        $this->networkSettings = $networkSettings ?? new NetworkSettingsRepository();
    }

    public function get(): BackgroundSendingSettings
    {
        $settings = $this->settings->getAll();
        $background = isset($settings[self::KEY]) && is_array($settings[self::KEY]) ? $settings[self::KEY] : [];

        return BackgroundSendingSettings::fromArray($this->networkSettings->resolve(self::KEY, $background));
    }

    public function save(BackgroundSendingSettings $background): bool
    {
        $settings = $this->settings->getAll();
        $settings[self::KEY] = $background->toArray();

        return $this->settings->save($settings, 'onesmtp_settings_nonce');
    }
}
