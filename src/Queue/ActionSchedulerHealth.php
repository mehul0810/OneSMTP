<?php

declare(strict_types=1);

namespace OneSMTP\Queue;

class ActionSchedulerHealth
{
    public function isAvailable(): bool
    {
        return function_exists('as_schedule_single_action')
            && function_exists('as_has_scheduled_action');
    }
}
