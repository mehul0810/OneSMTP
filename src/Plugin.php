<?php

declare(strict_types=1);

namespace OneSMTP;

use OneSMTP\Admin\AdminPage;
use OneSMTP\Cli\DiagnosticsCommand;
use OneSMTP\Api\RestController;
use OneSMTP\Core\Installer;
use OneSMTP\Conflict\MailDeliveryOwnership;
use OneSMTP\Diagnostics\DiagnosticReportGenerator;
use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Product\FeatureGate;
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
use OneSMTP\Settings\BackgroundSendingSettingsRepository;
use OneSMTP\Settings\SenderIdentityRepository;
use OneSMTP\Settings\SimulationModeSettingsRepository;
use OneSMTP\Summary\WeeklySummaryMailer;

final class Plugin
{
    public function boot(): void
    {
        Installer::maybeUpgrade();

        $featureGate = FeatureGate::fromRuntime();
        $dispatchPolicy = new DefaultDispatchPolicy(featureGate: $featureGate);
        $deliveryOwnership = new MailDeliveryOwnership();

        $messages  = new MessageRepository();
        $attempts  = new AttemptRepository();
        $providers = new ProviderRepository();
        $events    = new EventRepository();
        $stateCache = new ProviderStateCache();
        $stateCache->registerInvalidationHooks();

        $schedulerHealth = new ActionSchedulerHealth();
        $queueDiagnostics = new QueueDiagnostics($schedulerHealth, $messages);
        $retryScheduler = new RetryScheduler($dispatchPolicy, $messages, $attempts, $providers, $events, $deliveryOwnership);
        $retryScheduler->registerHooks();

        $weeklySummary = new WeeklySummaryMailer(null, new MetricsRepository());
        $weeklySummary->registerHooks();

        $deliveryEngine = new DeliveryEngine($providers, $attempts, $dispatchPolicy);
        $rateLimiter = new RateLimiter($attempts);
        $backgroundSending = new BackgroundSendingSettingsRepository();
        $senderIdentity = new SenderIdentityApplier();
        if ($deliveryOwnership->canAculectDeliver()) {
            $senderIdentity->registerHooks();
        }

        $sendPipeline = new SendPipeline($messages, $attempts, $providers, $events, $retryScheduler, $deliveryEngine, $rateLimiter, $backgroundSending, null, new SimulationModeSettingsRepository(), $deliveryOwnership);
        $sendPipeline->registerHooks();

        $adminPage = new AdminPage(
            null,
            null,
            new Admin\LogAdmin(
                $messages,
                $attempts,
                $providers,
                null,
                static fn (int $messageId, ?int $providerId): bool => $sendPipeline->resendMessage($messageId, $providerId),
                null,
                null,
                static fn (int $messageId): bool => $retryScheduler->scheduleImmediateRetry($messageId),
                $featureGate
            ),
            null,
            new Admin\QueueDiagnosticsAdmin($queueDiagnostics, new DiagnosticReportGenerator($providers, $queueDiagnostics, $attempts)),
            new Admin\DashboardAdmin(new MetricsRepository()),
            null,
            null,
            null,
            $deliveryOwnership,
            null,
            $featureGate,
            new Admin\RoutingAdmin(null, $providers, $featureGate)
        );
        $adminPage->registerHooks();

        DiagnosticsCommand::register(new DiagnosticsCommand(new DiagnosticReportGenerator($providers, $queueDiagnostics, $attempts)));

        add_action(
            'rest_api_init',
            static function () use ($providers, $messages, $attempts, $sendPipeline): void {
                $controller = new RestController($providers, $messages, $attempts, $sendPipeline, null, new SenderIdentityRepository());
                $controller->registerRoutes();
            }
        );

        $retentionPruner = new RetentionPruner();
        $retentionPruner->registerHooks();
    }
}
