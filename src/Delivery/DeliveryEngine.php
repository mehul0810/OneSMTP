<?php

declare(strict_types=1);

namespace OneSMTP\Delivery;

use OneSMTP\Dispatch\DispatchPolicyInterface;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\ProviderRepository;

final class DeliveryEngine
{
    private ProviderRepository $providers;
    private AttemptRepository $attempts;
    private DispatchPolicyInterface $dispatchPolicy;
    private ProviderDeliveryManager $deliveryManager;

    public function __construct(
        ProviderRepository $providers,
        AttemptRepository $attempts,
        DispatchPolicyInterface $dispatchPolicy,
        ?ProviderDeliveryManager $deliveryManager = null
    ) {
        $this->providers = $providers;
        $this->attempts = $attempts;
        $this->dispatchPolicy = $dispatchPolicy;
        $this->deliveryManager = $deliveryManager ?? new ProviderDeliveryManager();
    }

    public function deliver(int $messageId, int $attemptNo, array $payload, ?int $forcedProviderId = null): DeliveryOutcome
    {
        $providerId = $this->resolveProviderId($messageId, $attemptNo, $forcedProviderId);
        if ($providerId <= 0) {
            return new DeliveryOutcome(false, 0, 'no_provider', 'No eligible provider available.');
        }

        $provider = $this->providers->find($providerId);
        if (! is_array($provider)) {
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

        return (int) $providerId;
    }
}
