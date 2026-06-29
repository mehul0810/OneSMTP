<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

final class AttachmentLoggingSettingsRepository
{
    private const KEY = 'attachment_logging';

    public function __construct(private ?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function get(): AttachmentLoggingSettings
    {
        $settings = $this->settings->getAll();
        $attachmentLogging = isset($settings[self::KEY]) && is_array($settings[self::KEY]) ? $settings[self::KEY] : [];

        return AttachmentLoggingSettings::fromArray($attachmentLogging);
    }

    public function save(AttachmentLoggingSettings $attachmentLogging): bool
    {
        $settings = $this->settings->getAll();
        $settings[self::KEY] = $attachmentLogging->toArray();

        return $this->settings->save($settings, 'onesmtp_settings_nonce');
    }
}
