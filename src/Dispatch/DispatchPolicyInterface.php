<?php

declare(strict_types=1);

namespace OneSMTP\Dispatch;

interface DispatchPolicyInterface
{
    /**
     * @param int   $messageId      Aculect Mail message identifier.
     * @param int   $attemptNumber  Current attempt number.
     * @param array $context        Provider health/attempt metadata.
     */
    public function chooseNextProvider(int $messageId, int $attemptNumber, array $context): ?int;
}
