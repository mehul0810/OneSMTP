<?php

declare(strict_types=1);

namespace OneSMTP\Delivery;

use OneSMTP\Dispatch\DispatchPolicyInterface;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\ProviderRepository;

final class DeliveryEngine
{
    private ProviderRepository $providers;
    private AttemptRepository $attempts;
    private DispatchPolicyInterface $dispatchPolicy;
    private ProviderDeliveryManager $deliveryManager;
    private EventRepository $events;

    public function __construct(
        ProviderRepository $providers,
        AttemptRepository $attempts,
        DispatchPolicyInterface $dispatchPolicy,
        ?ProviderDeliveryManager $deliveryManager = null,
        ?EventRepository $events = null
    ) {
        $this->providers = $providers;
        $this->attempts = $attempts;
        $this->dispatchPolicy = $dispatchPolicy;
        $this->deliveryManager = $deliveryManager ?? new ProviderDeliveryManager();
        $this->events = $events ?? new EventRepository();
    }

    public function deliver(int $messageId, int $attemptNo, array $payload, ?int $forcedProviderId = null): DeliveryOutcome
    {
        if ($forcedProviderId !== null && $forcedProviderId > 0 && ! $this->isEligibleForcedProvider($forcedProviderId)) {
            $this->events->add(
                'manual_resend_rejected',
                ['attempt' => $attemptNo, 'reason' => 'ineligible_provider'],
                $messageId,
                $forcedProviderId
            );

            return new DeliveryOutcome(false, $forcedProviderId, 'ineligible_provider', 'Selected provider is not eligible for resend.');
        }

        $providerId = $this->resolveProviderId($messageId, $attemptNo, $forcedProviderId);
        if ($providerId <= 0) {
            $this->events->add('terminal_failure', ['attempt' => $attemptNo, 'reason' => 'provider_pool_exhausted'], $messageId);

            return new DeliveryOutcome(false, 0, 'no_provider', 'No eligible provider available.');
        }

        $provider = $this->providers->find($providerId);
        if (! is_array($provider)) {
            $this->events->add('terminal_failure', ['attempt' => $attemptNo, 'reason' => 'missing_provider'], $messageId, $providerId);

            return new DeliveryOutcome(false, 0, 'missing_provider', 'Provider not found.');
        }

        $result = $this->deliveryManager->send($provider, $payload);

        if ($result->isSuccess()) {
            $this->providers->markState($providerId, 'closed', null);
        } else {
            $this->providers->markState($providerId, 'open', gmdate('Y-m-d H:i:s', time() + 300));
        }

        return new DeliveryOutcome(
            $result->isSuccess(),
            $providerId,
            $result->getCode(),
            $result->getMessage(),
            $result->getProviderMessageId()
        );
    }

    private function resolveProviderId(int $messageId, int $attemptNo, ?int $forcedProviderId): int
    {
        $providers = $this->providers->getActiveProviders();
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
            ]
        );

        $this->recordProviderSwitch($messageId, $attemptNo, $lastProviderId, (int) $providerId);

        return (int) $providerId;
    }

    private function isEligibleForcedProvider(int $providerId): bool
    {
        foreach ($this->providers->getActiveProviders() as $provider) {
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
