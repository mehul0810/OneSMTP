<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

use OneSMTP\Multisite\NetworkSettingsRepository;

final class AttachmentLoggingSettingsRepository
{
    private const KEY = 'attachment_logging';

    public function __construct(
        private ?SettingsRepository $settings = null,
        private ?NetworkSettingsRepository $networkSettings = null
    )
    {
        $this->settings = $settings ?? new SettingsRepository();
        $this->networkSettings = $networkSettings ?? new NetworkSettingsRepository();
    }

    public function get(): AttachmentLoggingSettings
    {
        $settings = $this->settings->getAll();
        $attachmentLogging = isset($settings[self::KEY]) && is_array($settings[self::KEY]) ? $settings[self::KEY] : [];

        return AttachmentLoggingSettings::fromArray($this->networkSettings->resolve(self::KEY, $attachmentLogging));
    }

    public function save(AttachmentLoggingSettings $attachmentLogging): bool
    {
        $settings = $this->settings->getAll();
        $settings[self::KEY] = $attachmentLogging->toArray();

        return $this->settings->save($settings, 'onesmtp_settings_nonce');
    }
}
