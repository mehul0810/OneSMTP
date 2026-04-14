<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Integration\Dispatch;

use PHPUnit\Framework\TestCase;

final class LoggingIntegrationTest extends TestCase
{
    public function test_each_attempt_logs_provider_and_outcome_todo(): void
    {
        self::markTestIncomplete('TODO: Assert attempt-level logging for provider, status, error, and retry count.');
    }

    public function test_terminal_state_written_after_retry_exhaustion_todo(): void
    {
        self::markTestIncomplete('TODO: Assert terminal failed state exists after max retry threshold.');
    }
}
