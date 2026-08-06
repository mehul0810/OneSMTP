<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

final class SimulationModeSettingsRepository
{
    private const KEY = 'simulation_mode';

    public function __construct(private ?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function get(): SimulationModeSettings
    {
        $all = $this->settings->getAll();
        $value = isset($all[ self::KEY ]) && is_array($all[ self::KEY ]) ? $all[ self::KEY ] : [];

        return SimulationModeSettings::fromArray($value);
    }

    public function save(SimulationModeSettings $simulation): bool
    {
        $all = $this->settings->getAll();
        $all[ self::KEY ] = $simulation->toArray();

        return $this->settings->save($all, 'onesmtp_settings_nonce');
    }
}
