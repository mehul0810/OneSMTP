<?php

declare(strict_types=1);

namespace OneSMTP\Events;

interface ReplayTokenHashProviderInterface
{
    /**
     * Return the opaque digest of the token from the most recent verified
     * payload. Raw Mailgun tokens never leave the verifier.
     */
    public function getReplayTokenHash(): ?string;
}
