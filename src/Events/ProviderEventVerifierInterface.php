<?php

declare(strict_types=1);

namespace OneSMTP\Events;

interface ProviderEventVerifierInterface
{
    /**
     * Verify an already-bounded request body and its normalized headers.
     *
     * @param array<string,string> $headers
     */
    public function verify(string $payload, array $headers): ProviderEventVerificationResult;
}
