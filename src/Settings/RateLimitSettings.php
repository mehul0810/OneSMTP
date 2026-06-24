<?php

declare(strict_types=1);

namespace OneSMTP\Settings;

final class RateLimitSettings
{
    private const MAX_LIMIT = 1000000;

    public function __construct(
        private int $perMinute = 0,
        private int $perHour = 0,
        private int $perDay = 0
    ) {
        $this->perMinute = $this->normalize($perMinute);
        $this->perHour   = $this->normalize($perHour);
        $this->perDay    = $this->normalize($perDay);
    }

    public static function fromArray(array $settings): self
    {
        return new self(
            isset($settings['per_minute']) ? (int) $settings['per_minute'] : 0,
            isset($settings['per_hour']) ? (int) $settings['per_hour'] : 0,
            isset($settings['per_day']) ? (int) $settings['per_day'] : 0
        );
    }

    public function toArray(): array
    {
        return [
            'per_minute' => $this->perMinute,
            'per_hour'   => $this->perHour,
            'per_day'    => $this->perDay,
        ];
    }

    public function getPerMinute(): int
    {
        return $this->perMinute;
    }

    public function getPerHour(): int
    {
        return $this->perHour;
    }

    public function getPerDay(): int
    {
        return $this->perDay;
    }

    public function hasAnyLimit(): bool
    {
        return $this->perMinute > 0 || $this->perHour > 0 || $this->perDay > 0;
    }

    private function normalize(int $value): int
    {
        return max(0, min(self::MAX_LIMIT, $value));
    }
}
