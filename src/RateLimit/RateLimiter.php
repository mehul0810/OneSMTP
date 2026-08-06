<?php

declare(strict_types=1);

namespace OneSMTP\RateLimit;

use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Settings\RateLimitSettings;
use OneSMTP\Settings\RateLimitSettingsRepository;

final class RateLimiter
{
    private const GROUP = 'onesmtp';
    private const SEND_LOCK_KEY = 'rate_limit_send_lock';
    private const SEND_LOCK_TTL = 60;

    /**
     * @var callable():int
     */
    private $clock;

    public function __construct(
        private AttemptRepository $attempts,
        private ?RateLimitSettingsRepository $settings = null,
        ?callable $clock = null
    ) {
        $this->settings = $settings ?? new RateLimitSettingsRepository();
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function evaluate(): RateLimitDecision
    {
        $settings = $this->settings->get();
        if (! $settings->hasAnyLimit()) {
            return RateLimitDecision::allowed();
        }

        $now = max(1, (int) call_user_func($this->clock));
        $blocked = null;

        foreach ($this->configuredWindows($settings) as $window) {
            $since = gmdate('Y-m-d H:i:s', $now - $window['seconds']);
            $stats = $this->attempts->getSuccessfulSendWindowStats($since);
            $used = (int) ($stats['sent_count'] ?? 0);

            if ($used < $window['limit']) {
                continue;
            }

            $oldest = isset($stats['oldest_created_at']) ? strtotime((string) $stats['oldest_created_at']) : false;
            $resetAt = is_int($oldest) ? $oldest + $window['seconds'] : $now + $window['seconds'];
            $retryAfter = max(1, $resetAt - $now);

            if ($blocked === null || $retryAfter > $blocked['retry_after']) {
                $blocked = [
                    'retry_after' => $retryAfter,
                    'window'      => $window['name'],
                    'limit'       => $window['limit'],
                    'used'        => $used,
                ];
            }
        }

        if ($blocked === null) {
            return RateLimitDecision::allowed();
        }

        return RateLimitDecision::limited(
            (int) $blocked['retry_after'],
            (string) $blocked['window'],
            (int) $blocked['limit'],
            (int) $blocked['used']
        );
    }

    public function acquireSendLock(): bool
    {
        if (! $this->settings->get()->hasAnyLimit()) {
            return true;
        }

        if (function_exists('wp_cache_add') && wp_using_ext_object_cache()) {
            return (bool) wp_cache_add(self::SEND_LOCK_KEY, 1, self::GROUP, self::SEND_LOCK_TTL);
        }

        if (get_transient(self::SEND_LOCK_KEY) !== false) {
            return false;
        }

        return set_transient(self::SEND_LOCK_KEY, 1, self::SEND_LOCK_TTL);
    }

    public function releaseSendLock(): void
    {
        if (function_exists('wp_cache_delete') && wp_using_ext_object_cache()) {
            wp_cache_delete(self::SEND_LOCK_KEY, self::GROUP);
        }

        delete_transient(self::SEND_LOCK_KEY);
    }

    /**
     * @return array<int,array{name:string,seconds:int,limit:int}>
     */
    private function configuredWindows(RateLimitSettings $settings): array
    {
        $windows = [
            ['name' => 'minute', 'seconds' => 60, 'limit' => $settings->getPerMinute()],
            ['name' => 'hour', 'seconds' => HOUR_IN_SECONDS, 'limit' => $settings->getPerHour()],
            ['name' => 'day', 'seconds' => DAY_IN_SECONDS, 'limit' => $settings->getPerDay()],
        ];

        return array_values(
            array_filter(
                $windows,
                static fn (array $window): bool => $window['limit'] > 0
            )
        );
    }
}
