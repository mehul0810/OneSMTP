<?php

declare(strict_types=1);

namespace OneSMTP\Quota;

final class ProviderQuotaSettingsKey
{
    public const PER_MINUTE = 'quota_per_minute';
    public const PER_HOUR = 'quota_per_hour';
    public const PER_DAY = 'quota_per_day';

    /** @return array<int,string> */
    public static function fields(): array
    {
        return [self::PER_MINUTE, self::PER_HOUR, self::PER_DAY];
    }
}
