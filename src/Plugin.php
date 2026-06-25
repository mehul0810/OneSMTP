<?php

declare(strict_types=1);

namespace OneSMTP;

use OneSMTP\Admin\AdminPage;
use OneSMTP\Admin\MailConflictNotice;
use OneSMTP\Admin\SchedulerNotice;
use OneSMTP\Cli\DiagnosticsCommand;
use OneSMTP\Conflict\MailConflictDetector;
use OneSMTP\Api\RestController;
use OneSMTP\Core\Installer;
use OneSMTP\Diagnostics\DiagnosticReportGenerator;
use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Logging\RetentionPruner;
use OneSMTP\Pipeline\SenderIdentityApplier;
use OneSMTP\Pipeline\SendPipeline;
use OneSMTP\Providers\ProviderStateCache;
use OneSMTP\Queue\ActionSchedulerHealth;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\RateLimit\RateLimiter;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MetricsRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;

final class Plugin
{
    public function boot(): void
    {
        Installer::maybeUpgrade();

        $dispatchPolicy = new DefaultDispatchPolicy();

        $messages  = new MessageRepository();
        $attempts  = new AttemptRepository();
        $providers = new ProviderRepository();
        $events    = new EventRepository();
        $stateCache = new ProviderStateCache();
        $stateCache->registerInvalidationHooks();

        $schedulerHealth = new ActionSchedulerHealth();
        $queueDiagnostics = new QueueDiagnostics($schedulerHealth, $messages);
        $schedulerNotice = new SchedulerNotice($schedulerHealth, $providers, $queueDiagnostics);
        $schedulerNotice->registerHooks();

        $mailConflictNotice = new MailConflictNotice(new MailConflictDetector());
        $mailConflictNotice->registerHooks();

        $retryScheduler = new RetryScheduler($dispatchPolicy, $messages, $attempts, $providers, $events);
        $retryScheduler->registerHooks();

        $deliveryEngine = new DeliveryEngine($providers, $attempts, $dispatchPolicy);
        $rateLimiter = new RateLimiter($attempts);
        $senderIdentity = new SenderIdentityApplier();
        $senderIdentity->registerHooks();

        $sendPipeline = new SendPipeline($messages, $attempts, $providers, $events, $retryScheduler, $deliveryEngine, $rateLimiter);
        $sendPipeline->registerHooks();

        $adminPage = new AdminPage(
            null,
            null,
            new Admin\LogAdmin(
                $messages,
                $attempts,
                $providers,
                null,
                static fn (int $messageId, ?int $providerId): bool => $sendPipeline->resendMessage($messageId, $providerId)
            ),
            null,
            new Admin\QueueDiagnosticsAdmin($queueDiagnostics, new DiagnosticReportGenerator($providers, $queueDiagnostics, $attempts)),
            new Admin\DashboardAdmin(new MetricsRepository())
        );
        $adminPage->registerHooks();

        DiagnosticsCommand::register(new DiagnosticsCommand(new DiagnosticReportGenerator($providers, $queueDiagnostics, $attempts)));

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
