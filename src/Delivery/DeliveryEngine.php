<?php

declare(strict_types=1);

namespace OneSMTP\Delivery;

use OneSMTP\Dispatch\DispatchPolicyInterface;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\FailureCategory;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Quota\ProviderQuotaDecision;
use OneSMTP\Quota\ProviderQuotaManager;

final class DeliveryEngine
{
    private ProviderRepository $providers;
    private AttemptRepository $attempts;
    private DispatchPolicyInterface $dispatchPolicy;
    private ProviderDeliveryManager $deliveryManager;
    private EventRepository $events;

    /** @var callable():int */
    private $monotonicNow;
    private ProviderQuotaManager $providerQuota;

    public function __construct(
        ProviderRepository $providers,
        AttemptRepository $attempts,
        DispatchPolicyInterface $dispatchPolicy,
        ?ProviderDeliveryManager $deliveryManager = null,
        ?EventRepository $events = null,
        ?callable $monotonicNow = null,
        ?ProviderQuotaManager $providerQuota = null
    ) {
        $this->providers = $providers;
        $this->attempts = $attempts;
        $this->dispatchPolicy = $dispatchPolicy;
        $this->deliveryManager = $deliveryManager ?? new ProviderDeliveryManager();
        $this->events = $events ?? new EventRepository();
        $this->monotonicNow = $monotonicNow ?? static fn (): int => hrtime(true);
        $this->providerQuota = $providerQuota ?? new ProviderQuotaManager($attempts);
    }

    public function deliver(
        int $messageId,
        int $attemptNo,
        array $payload,
        ?int $forcedProviderId = null,
        bool $failoverOnFirstFailure = false,
        bool $allowQuotaFallbackForForced = false
    ): DeliveryOutcome
    {
        $candidates = $this->eligibleProviders($this->providers->getActiveProviders(), $payload);
        if (! $this->providerQuota->acquireLock($candidates)) {
            return DeliveryOutcome::deferred(
                $this->providerQuota->getLockRetryAfter(),
                null,
                'provider_quota_check_deferred',
                'Provider sending budget checks are busy; delivery will retry.'
            );
        }

        try {
            $quotaSelection = $this->providerQuota->filterProviders($candidates);
            $quotaProviders = $quotaSelection['providers'];
            $quotaDeferred = $quotaSelection['deferred'];

            if ($forcedProviderId !== null && $forcedProviderId > 0) {
                $forcedProvider = $this->findProvider($candidates, $forcedProviderId);
                if (! is_array($forcedProvider) || ! $this->isEligibleForcedProvider($forcedProviderId, $candidates)) {
                    $this->events->add(
                        'manual_resend_rejected',
                        ['attempt' => $attemptNo, 'reason' => 'ineligible_provider'],
                        $messageId,
                        $forcedProviderId
                    );

                    return new DeliveryOutcome(false, $forcedProviderId, 'ineligible_provider', 'Selected provider is not eligible for resend.');
                }

                if ($this->findProvider($quotaProviders, $forcedProviderId) === null) {
                    $forcedDecision = $this->providerQuota->evaluateProvider($forcedProvider);
                    if ($allowQuotaFallbackForForced && $quotaProviders !== []) {
                        $forcedProviderId = null;
                    } elseif (! $forcedDecision->canSend()) {
                        return $this->quotaDeferredOutcome($forcedDecision);
                    } elseif ($quotaDeferred instanceof ProviderQuotaDecision) {
                        return $this->quotaDeferredOutcome($quotaDeferred);
                    }
                }
            }

            if ($quotaProviders === [] && $quotaDeferred instanceof ProviderQuotaDecision) {
                return $this->quotaDeferredOutcome($quotaDeferred);
            }

            $providerId = $this->resolveProviderId($messageId, $attemptNo, $payload, $forcedProviderId, $failoverOnFirstFailure, $quotaProviders);
            if ($providerId <= 0) {
                $this->events->add('terminal_failure', ['attempt' => $attemptNo, 'reason' => 'provider_pool_exhausted'], $messageId);

                return new DeliveryOutcome(false, 0, 'no_provider', 'No eligible provider available.');
            }

            $provider = $this->providers->find($providerId);
            if (! is_array($provider)) {
                $this->events->add('terminal_failure', ['attempt' => $attemptNo, 'reason' => 'missing_provider'], $messageId, $providerId);

                return new DeliveryOutcome(false, 0, 'missing_provider', 'Provider not found.');
            }

            $startedAt = ($this->monotonicNow)();
            $result = $this->deliveryManager->send($provider, $payload);
            $latencyMs = max(0, (int) round(((($this->monotonicNow)()) - $startedAt) / 1_000_000));
            $this->providerQuota->reserveProvider($providerId);

            if ($result->isSuccess()) {
                $this->providers->markState($providerId, 'closed', null);
            } elseif (FailureCategory::affectsProviderHealth($result->getFailureCategory())) {
                $this->providers->markState($providerId, 'open', gmdate('Y-m-d H:i:s', time() + 300));
            }

            return new DeliveryOutcome(
                $result->isSuccess(),
                $providerId,
                $result->getCode(),
                $result->getMessage(),
                $result->getProviderMessageId(),
                $result->getFailureCategory(),
                $latencyMs
            );
        } finally {
            $this->providerQuota->releaseLock();
        }
    }

    private function resolveProviderId(
        int $messageId,
        int $attemptNo,
        array $payload,
        ?int $forcedProviderId,
        bool $failoverOnFirstFailure,
        array $providers
    ): int
    {
        $lastAttempt = $this->attempts->getLastAttemptForMessage($messageId);
        $lastProviderId = is_array($lastAttempt) ? (int) ($lastAttempt['provider_id'] ?? 0) : 0;
        $consecutive = $lastProviderId > 0
            ? $this->attempts->countConsecutiveFailuresForProvider($messageId, $lastProviderId)
            : 0;

        $providerId = $this->dispatchPolicy->chooseNextProvider(
            $messageId,
            $attemptNo,
            [
                'providers' => $providers,
                'last_provider_id' => $lastProviderId,
                'consecutive_failures_for_last_provider' => $consecutive,
                'forced_provider_id' => $forcedProviderId ?? 0,
                'failover_on_first_failure' => $failoverOnFirstFailure,
                'payload' => $payload,
            ]
        );

        $this->recordProviderSwitch($messageId, $attemptNo, $lastProviderId, (int) $providerId);

        return (int) $providerId;
    }

    /** @param array<int,array<string,mixed>> $providers */
    private function isEligibleForcedProvider(int $providerId, array $providers): bool
    {
        foreach ($providers as $provider) {
            if ((int) ($provider['id'] ?? 0) !== $providerId) {
                continue;
            }

            if ((int) ($provider['is_active'] ?? 0) !== 1) {
                return false;
            }

            if ((string) ($provider['circuit_state'] ?? 'closed') !== 'open') {
                return true;
            }

            $until = isset($provider['circuit_until']) ? strtotime((string) $provider['circuit_until']) : false;

            return ! is_int($until) || $until <= time();
        }

        return false;
    }

    /** @param array<int,array<string,mixed>> $providers */
    private function findProvider(array $providers, int $providerId): ?array
    {
        foreach ($providers as $provider) {
            if ((int) ($provider['id'] ?? 0) === $providerId) {
                return $provider;
            }
        }

        return null;
    }

    private function quotaDeferredOutcome(ProviderQuotaDecision $decision): DeliveryOutcome
    {
        return DeliveryOutcome::deferred(
            max(1, $decision->getRetryAfter()),
            $decision->getNextCapacityAt()
        );
    }

    public function releaseQuotaReservation(int $providerId): void
    {
        $this->providerQuota->releaseProviderReservation($providerId);
    }

    /** @param array<int,array<string,mixed>> $providers */
    private function eligibleProviders(array $providers, array $payload): array
    {
        $attachments = $payload['attachments'] ?? [];
        $hasAttachments = is_array($attachments) ? $attachments !== [] : trim((string) $attachments) !== '';
        if (! $hasAttachments) {
            return $providers;
        }

        return array_values(array_filter(
            $providers,
            static fn (array $provider): bool => ProviderTypes::supportsCapability(
                (string) ($provider['adapter_type'] ?? ''),
                'attachments'
            )
        ));
    }

    private function recordProviderSwitch(int $messageId, int $attemptNo, int $fromProviderId, int $toProviderId): void
    {
        if ($fromProviderId <= 0 || $toProviderId <= 0 || $fromProviderId === $toProviderId) {
            return;
        }

        $this->events->add(
            'provider_failover',
            [
                'attempt' => $attemptNo,
                'from_provider_id' => $fromProviderId,
                'to_provider_id' => $toProviderId,
            ],
            $messageId,
            $toProviderId
        );
    }
}
