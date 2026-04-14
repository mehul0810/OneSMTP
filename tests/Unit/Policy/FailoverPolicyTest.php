<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Policy;

use OneSMTP\Tests\Support\PolicyFixtures;
use PHPUnit\Framework\TestCase;

final class FailoverPolicyTest extends TestCase
{
    public function test_primary_success_keeps_provider_unchanged_todo(): void
    {
        $context = PolicyFixtures::attemptContext([
            'current_provider' => 'primary',
            'failure_count_for_current_provider' => 0,
        ]);

        self::assertSame('primary', $context['current_provider']);
        self::markTestIncomplete('TODO: Replace fixture assertion with FailoverPolicy::nextProvider() once implemented.');
    }

    public function test_switches_to_secondary_after_two_failures_todo(): void
    {
        $context = PolicyFixtures::attemptContext([
            'current_provider' => 'primary',
            'failure_count_for_current_provider' => 2,
        ]);

        self::assertSame(2, $context['failure_count_for_current_provider']);
        self::markTestIncomplete('TODO: Assert provider switches to secondary after second primary failure.');
    }

    public function test_cycles_providers_when_more_than_two_configured_todo(): void
    {
        $providers = PolicyFixtures::providerPoolMulti();

        self::assertCount(3, $providers);
        self::markTestIncomplete('TODO: Assert deterministic rotation order for 3+ providers.');
    }
}
