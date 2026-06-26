<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

final class AttachmentLoggingSettings
{
    public function __construct(private bool $enabled = false)
    {
    }

    public static function fromArray(array $settings): self
    {
        return new self(! empty($settings['enabled']));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
        ];
    }
}
