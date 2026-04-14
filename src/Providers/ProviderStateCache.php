<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

final class ProviderStateCache
{
    private const CACHE_KEY = 'onesmtp_active_provider_pool';
    private const GROUP = 'onesmtp';

    public function remember(array $providers): void
    {
        wp_cache_set(self::CACHE_KEY, $providers, self::GROUP, 60);
    }

    public function get(): ?array
    {
        $providers = wp_cache_get(self::CACHE_KEY, self::GROUP);

        return is_array($providers) ? $providers : null;
    }

    public function flush(): void
    {
        wp_cache_delete(self::CACHE_KEY, self::GROUP);
    }

    public function registerInvalidationHooks(): void
    {
        add_action('onesmtp_provider_saved', [$this, 'flush']);
        add_action('onesmtp_provider_deleted', [$this, 'flush']);
        add_action('onesmtp_provider_state_changed', [$this, 'flush']);
    }
}
