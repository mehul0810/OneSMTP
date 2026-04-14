<?php

declare(strict_types=1);

namespace OneSMTP;

use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Queue\RetryScheduler;

final class Plugin
{
    public function boot(): void
    {
        $retryScheduler = new RetryScheduler(new DefaultDispatchPolicy());
        $retryScheduler->registerHooks();
    }
}
