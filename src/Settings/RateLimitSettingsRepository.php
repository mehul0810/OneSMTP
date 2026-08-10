<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

use OneSMTP\Multisite\NetworkSettingsRepository;

final class RateLimitSettingsRepository
{
    private const KEY = 'rate_limits';

    public function __construct(
        private ?SettingsRepository $settings = null,
        private ?NetworkSettingsRepository $networkSettings = null
    )
    {
        $this->settings = $settings ?? new SettingsRepository();
        $this->networkSettings = $networkSettings ?? new NetworkSettingsRepository();
    }

    public function get(): RateLimitSettings
    {
        $settings = $this->settings->getAll();
        $limits = isset($settings[self::KEY]) && is_array($settings[self::KEY]) ? $settings[self::KEY] : [];

        return RateLimitSettings::fromArray($this->networkSettings->resolve(self::KEY, $limits));
    }

    public function save(RateLimitSettings $limits): bool
    {
        $settings = $this->settings->getAll();
        $settings[self::KEY] = $limits->toArray();

        return $this->settings->save($settings, 'onesmtp_settings_nonce');
    }
}
