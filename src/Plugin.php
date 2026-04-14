<?php

declare(strict_types=1);

namespace OneSMTP;

use OneSMTP\Api\RestController;
use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Logging\RetentionPruner;
use OneSMTP\Pipeline\SendPipeline;
use OneSMTP\Providers\ProviderStateCache;
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
        $stateCache = new ProviderStateCache();
        $stateCache->registerInvalidationHooks();

        $retryScheduler = new RetryScheduler($dispatchPolicy, $messages, $attempts, $providers, $events);
        $retryScheduler->registerHooks();

        $deliveryEngine = new DeliveryEngine($providers, $attempts, $dispatchPolicy);
        $sendPipeline = new SendPipeline($messages, $attempts, $providers, $events, $retryScheduler, $deliveryEngine);
        $sendPipeline->registerHooks();

        add_action(
            'rest_api_init',
            static function () use ($providers, $messages, $attempts, $sendPipeline): void {
                $controller = new RestController($providers, $messages, $attempts, $sendPipeline);
                $controller->registerRoutes();
            }
        );

        $retentionPruner = new RetentionPruner();
        $retentionPruner->registerHooks();
    }
}
