<?php

declare(strict_types=1);

namespace OneSMTP;

use OneSMTP\Admin\AdminPage;
use OneSMTP\Cli\DiagnosticsCommand;
use OneSMTP\Api\RestController;
use OneSMTP\Api\ProviderEventController;
use OneSMTP\Core\Installer;
use OneSMTP\Conflict\MailDeliveryOwnership;
use OneSMTP\Diagnostics\DiagnosticReportGenerator;
use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Logging\RetentionPruner;
use OneSMTP\Alerts\FailureAlertDispatcher;
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
use OneSMTP\Repository\ProviderEventRepository;
use OneSMTP\Events\MailgunEventNormalizer;
use OneSMTP\Events\MailgunEventVerifier;
use OneSMTP\Events\ProviderEventIngestionService;
use OneSMTP\Security\SiteSecretHmac;
use OneSMTP\Quota\ProviderQuotaManager;
use OneSMTP\Settings\BackgroundSendingSettingsRepository;
use OneSMTP\Settings\SenderIdentityRepository;
use OneSMTP\Settings\SimulationModeSettingsRepository;
use OneSMTP\Summary\WeeklySummaryMailer;
use OneSMTP\Multisite\NetworkLogRepository;
use OneSMTP\Multisite\NetworkSettingsRepository;
use OneSMTP\Repository\SuppressionRepository;
use OneSMTP\Suppression\SuppressionService;
use OneSMTP\Suppression\SuppressionSettingsRepository;

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
        $events    = new EventRepository(new FailureAlertDispatcher(null, null, $featureGate));
        $stateCache = new ProviderStateCache();
        $stateCache->registerInvalidationHooks();

        $schedulerHealth = new ActionSchedulerHealth();
        $queueDiagnostics = new QueueDiagnostics($schedulerHealth, $messages);
        $retryScheduler = new RetryScheduler($dispatchPolicy, $messages, $attempts, $providers, $events, $deliveryOwnership);
        $retryScheduler->registerHooks();

        $weeklySummary = new WeeklySummaryMailer(null, new MetricsRepository());
        $weeklySummary->registerHooks();

        $providerQuota = new ProviderQuotaManager($attempts, $featureGate);
        $deliveryEngine = new DeliveryEngine($providers, $attempts, $dispatchPolicy, null, $events, null, $providerQuota);
        $rateLimiter = new RateLimiter($attempts);
        $backgroundSending = new BackgroundSendingSettingsRepository();
        $suppression = null;
        $senderIdentity = new SenderIdentityApplier();
        if ($deliveryOwnership->canAculectDeliver()) {
            $senderIdentity->registerHooks();
        }

        $siteSecret = function_exists('wp_salt') ? trim( (string) wp_salt('auth') ) : '';
        $providerEventIngestion = null;
        if ($siteSecret !== '') {
            $siteId = function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
            $suppression = new SuppressionService(
                $featureGate,
                new SuppressionSettingsRepository(),
                new SuppressionRepository(),
                new SiteSecretHmac($siteSecret),
                recipientContext: 'recipient.site.' . $siteId,
                derivations: new \OneSMTP\Repository\SuppressionDerivationRepository()
            );
            $providerEvents = new ProviderEventRepository();
            $providerEventIngestion = new ProviderEventIngestionService(
                $providers,
                $providerEvents,
                $featureGate,
                new MailgunEventNormalizer(new SiteSecretHmac($siteSecret), recipientContext: 'recipient.site.' . $siteId),
                static fn (string $signingKey): MailgunEventVerifier => new MailgunEventVerifier($signingKey),
                null,
                static function (\OneSMTP\Events\ProviderEvent $event, ?int $providerId) use (&$suppression): bool {
                    return $suppression->derive($event, $providerId);
                }
            );
        }

        $sendPipeline = new SendPipeline($messages, $attempts, $providers, $events, $retryScheduler, $deliveryEngine, $rateLimiter, $backgroundSending, null, new SimulationModeSettingsRepository(), $deliveryOwnership, $suppression);
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
            new Admin\SettingsAdmin(featureGate: $featureGate, suppression: $suppression),
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

        $networkSettings = new NetworkSettingsRepository($featureGate);
        (new Admin\NetworkAdmin(
            $networkSettings,
            new NetworkLogRepository($networkSettings, $featureGate),
            $featureGate
        ))->registerHooks();

        DiagnosticsCommand::register(new DiagnosticsCommand(new DiagnosticReportGenerator($providers, $queueDiagnostics, $attempts)));

        add_action(
            'rest_api_init',
            static function () use ($providers, $messages, $attempts, $sendPipeline, $featureGate, $providerEventIngestion): void {
                $controller = new RestController($providers, $messages, $attempts, $sendPipeline, null, new SenderIdentityRepository(), null, $featureGate);
                $controller->registerRoutes();
                (new ProviderEventController($providerEventIngestion))->registerRoutes();
            }
        );

        $retentionPruner = new RetentionPruner();
        $retentionPruner->registerHooks();
    }
}
