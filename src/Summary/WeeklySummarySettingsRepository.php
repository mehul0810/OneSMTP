<?php

declare(strict_types=1);

namespace OneSMTP\Summary;

use OneSMTP\Settings\SettingsRepository;

final class WeeklySummarySettingsRepository
{
    private const KEY = 'weekly_summary';

    public function __construct(private ?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function get(): WeeklySummarySettings
    {
        $settings = $this->settings->getAll();
        $summary = isset($settings[self::KEY]) && is_array($settings[self::KEY]) ? $settings[self::KEY] : [];

        return WeeklySummarySettings::fromArray($summary);
    }

    public function save(WeeklySummarySettings $summary): bool
    {
        $settings = $this->settings->getAll();
        $settings[self::KEY] = $summary->toArray();

        return $this->settings->save($settings, 'onesmtp_settings_nonce');
    }
}
