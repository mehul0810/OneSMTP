<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

final class SenderIdentityRepository
{
    private const KEY = 'sender_identity';

    public function __construct(private ?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function get(): SenderIdentity
    {
        $settings = $this->settings->getAll();
        $sender = isset($settings[self::KEY]) && is_array($settings[self::KEY]) ? $settings[self::KEY] : [];

        return SenderIdentity::fromArray($sender);
    }

    public function save(SenderIdentity $identity): bool
    {
        $settings = $this->settings->getAll();
        $settings[self::KEY] = $identity->toArray();

        return $this->settings->save($settings, 'onesmtp_settings_nonce');
    }

    public function saveAuthorized(SenderIdentity $identity): bool
    {
        $settings = $this->settings->getAll();
        $settings[self::KEY] = $identity->toArray();

        return $this->settings->saveAuthorized($settings);
    }
}
