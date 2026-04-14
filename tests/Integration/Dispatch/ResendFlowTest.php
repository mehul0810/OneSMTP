<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Integration\Dispatch;

use PHPUnit\Framework\TestCase;

final class ResendFlowTest extends TestCase
{
    public function test_manual_resend_uses_selected_provider_todo(): void
    {
        self::markTestIncomplete('TODO: Add integration assertion when resend workflow and provider override service are implemented.');
    }

    public function test_manual_resend_keeps_attempt_lineage_todo(): void
    {
        self::markTestIncomplete('TODO: Assert resend attempt chain references original message ID in logs table.');
    }
}
