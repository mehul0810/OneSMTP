<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

/**
 * Builds the descriptor catalog for a registry's adapter declarations.
 *
 * Keeping this translation in one place prevents an adapter class from
 * silently drifting away from ProviderTypes metadata or its credential schema.
 */
final class ProviderAdapterCatalog
{
    /**
     * @param array<string,mixed> $adapters
     * @return array<string,ProviderAdapterDescriptor|mixed>
     */
    public static function fromAdapters(array $adapters): array
    {
        $descriptors = [];
        foreach ($adapters as $registrationSlug => $adapter) {
            if ($adapter instanceof ProviderAdapterInterface) {
                $descriptors[ (string) $registrationSlug ] = ProviderAdapterDescriptor::forProviderType(
                    (string) $registrationSlug,
                    $adapter
                );
                continue;
            }

            $descriptors[ (string) $registrationSlug ] = $adapter;
        }

        return $descriptors;
    }
}
