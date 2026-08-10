<?php

declare(strict_types=1);

namespace OneSMTP\Quota;

/**
 * Bounded, non-secret per-provider delivery budget settings.
 *
 * A zero value disables a window. Values are intentionally capped so malformed
 * admin or extension payloads cannot create unbounded query or scheduling work.
 */
final class ProviderQuotaSettings
{
    public const MAX_LIMIT = 1000000;

    public function __construct(
        private int $perMinute = 0,
        private int $perHour = 0,
        private int $perDay = 0
    ) {
        $this->perMinute = self::normalize($perMinute);
        $this->perHour = self::normalize($perHour);
        $this->perDay = self::normalize($perDay);
    }

    public static function fromArray(mixed $settings): self
    {
        if ( ! is_array($settings)) {
            return new self();
        }

        return new self(
            self::toInt($settings['per_minute'] ?? 0),
            self::toInt($settings['per_hour'] ?? 0),
            self::toInt($settings['per_day'] ?? 0)
        );
    }

    /** @param array<string,mixed> $config */
    public static function fromProviderConfig(array $config): self
    {
        return self::fromArray([
            'per_minute' => $config[ ProviderQuotaSettingsKey::PER_MINUTE ] ?? 0,
            'per_hour' => $config[ ProviderQuotaSettingsKey::PER_HOUR ] ?? 0,
            'per_day' => $config[ ProviderQuotaSettingsKey::PER_DAY ] ?? 0,
        ]);
    }

    /** @return array{quota_per_minute:int,quota_per_hour:int,quota_per_day:int} */
    public function toProviderConfig(): array
    {
        return [
            ProviderQuotaSettingsKey::PER_MINUTE => $this->perMinute,
            ProviderQuotaSettingsKey::PER_HOUR => $this->perHour,
            ProviderQuotaSettingsKey::PER_DAY => $this->perDay,
        ];
    }

    /** @return array{per_minute:int,per_hour:int,per_day:int} */
    public function toArray(): array
    {
        return [
            'per_minute' => $this->perMinute,
            'per_hour' => $this->perHour,
            'per_day' => $this->perDay,
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

    /** @return array<int,array{name:string,seconds:int,limit:int}> */
    public function configuredWindows(): array
    {
        return array_values(array_filter(
            [
                [
					'name' => 'minute',
					'seconds' => 60,
					'limit' => $this->perMinute,
				],
                [
					'name' => 'hour',
					'seconds' => HOUR_IN_SECONDS,
					'limit' => $this->perHour,
				],
                [
					'name' => 'day',
					'seconds' => DAY_IN_SECONDS,
					'limit' => $this->perDay,
				],
            ],
            static fn (array $window): bool => $window['limit'] > 0
        ));
    }

    private static function normalize(int $value): int
    {
        return max(0, min(self::MAX_LIMIT, $value));
    }

    private static function toInt(mixed $value): int
    {
        if (is_bool($value) || is_array($value) || is_object($value) || ( ! is_int($value) && ! is_float($value) && ! is_string($value))) {
            return 0;
        }

        $value = trim( (string) $value);
        if ($value === '' || ! preg_match('/^-?\d+$/', $value)) {
            return 0;
        }

        return (int) $value;
    }
}
