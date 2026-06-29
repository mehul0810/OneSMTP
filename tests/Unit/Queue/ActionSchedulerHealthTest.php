<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Queue;

use OneSMTP\Queue\ActionSchedulerHealth;
use PHPUnit\Framework\TestCase;

final class ActionSchedulerHealthTest extends TestCase
{
    public function test_reports_available_when_action_scheduler_functions_exist(): void
    {
        $health = new ActionSchedulerHealth();

        self::assertTrue($health->isAvailable());
    }
}
