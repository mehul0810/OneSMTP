<?php

declare(strict_types=1);

namespace OneSMTP;

use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Logging\RetentionPruner;
use OneSMTP\Pipeline\SendPipeline;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;

final class Plugin
{
    public function boot(): void
    {
        $dispatchPolicy = new DefaultDispatchPolicy();

        $messages  = new MessageRepository();
        $attempts  = new AttemptRepository();
        $providers = new ProviderRepository();
        $events    = new EventRepository();

        $retryScheduler = new RetryScheduler($dispatchPolicy, $messages, $attempts, $providers, $events);
        $retryScheduler->registerHooks();

        $sendPipeline = new SendPipeline($messages, $attempts, $providers, $events, $retryScheduler, $dispatchPolicy);
        $sendPipeline->registerHooks();

        $retentionPruner = new RetentionPruner();
        $retentionPruner->registerHooks();
    }
}
