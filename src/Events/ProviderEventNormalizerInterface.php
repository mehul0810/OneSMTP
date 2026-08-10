<?php

declare(strict_types=1);

namespace OneSMTP\Events;

interface ProviderEventNormalizerInterface
{
    /**
     * Normalize a verified provider payload into the safe event DTO.
     *
     * @param array<string,mixed> $payload
     */
    public function normalize(array $payload): ?ProviderEvent;
}
