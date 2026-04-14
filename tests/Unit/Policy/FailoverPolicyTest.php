<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Policy;

use PHPUnit\Framework\TestCase;

final class FailoverPolicyTest extends TestCase
{
    public function test_two_failure_switch_invariant_triggers_on_second_failure(): void
    {
        self::assertFalse($this->shouldSwitchProvider(0));
        self::assertFalse($this->shouldSwitchProvider(1));
        self::assertTrue($this->shouldSwitchProvider(2));
        self::assertTrue($this->shouldSwitchProvider(3));
    }

    public function test_two_failure_switch_is_provider_agnostic(): void
    {
        self::assertSame('secondary', $this->nextProviderAfterFailures('primary', 2));
        self::assertSame('primary', $this->nextProviderAfterFailures('secondary', 2));
    }

    public function test_rotation_placeholder_for_more_than_two_providers(): void
    {
        self::markTestIncomplete('TODO: Wire to concrete dispatch policy once weighted rotation is implemented in src/Dispatch.');
    }

    private function shouldSwitchProvider(int $consecutiveFailures): bool
    {
        return $consecutiveFailures >= 2;
    }

    private function nextProviderAfterFailures(string $currentProvider, int $consecutiveFailures): string
    {
        if (! $this->shouldSwitchProvider($consecutiveFailures)) {
            return $currentProvider;
        }

        return $currentProvider === 'primary' ? 'secondary' : 'primary';
    }
}
