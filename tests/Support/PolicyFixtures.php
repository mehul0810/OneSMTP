<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Support;

/**
 * Shared policy fixture payloads for deterministic retry/failover scenarios.
 *
 * TODO: Map these fixtures to real policy classes once implemented under src/.
 */
final class PolicyFixtures
{
    public static function providerPoolTwo(): array
    {
        return ['primary', 'secondary'];
    }

    public static function providerPoolMulti(): array
    {
        return ['provider_a', 'provider_b', 'provider_c'];
    }

    public static function attemptContext(array $overrides = []): array
    {
        return array_merge([
            'attempt' => 1,
            'max_retries' => 6,
            'failure_count_for_current_provider' => 0,
            'current_provider' => 'primary',
            'last_error_type' => null,
        ], $overrides);
    }
}
