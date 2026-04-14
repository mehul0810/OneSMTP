<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

final class ProviderDeliveryManager
{
    private ProviderAdapterRegistry $registry;

    public function __construct(?ProviderAdapterRegistry $registry = null)
    {
        $this->registry = $registry ?? new ProviderAdapterRegistry();
    }

    public function send(array $provider, array $messagePayload): SendResult
    {
        $providerType = (string) ($provider['adapter_type'] ?? '');
        $adapter = $this->registry->get($providerType);

        if (! $adapter instanceof ProviderAdapterInterface) {
            return new SendResult(false, 'adapter_missing', 'No adapter configured for provider type: ' . $providerType);
        }

        $config = new ProviderConfig(isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : []);

        return $adapter->send($messagePayload, $config);
    }

    public function testProvider(array $provider): SendResult
    {
        $providerType = (string) ($provider['adapter_type'] ?? '');
        $adapter = $this->registry->get($providerType);

        if (! $adapter instanceof ProviderAdapterInterface) {
            return new SendResult(false, 'adapter_missing', 'No adapter configured for provider type: ' . $providerType);
        }

        $config = new ProviderConfig(isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : []);

        return $adapter->testConnection($config);
    }
}
