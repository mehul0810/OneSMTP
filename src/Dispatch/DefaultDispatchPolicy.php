<?php

declare(strict_types=1);

namespace OneSMTP\Dispatch;

final class DefaultDispatchPolicy implements DispatchPolicyInterface
{
    public function chooseNextProvider(int $messageId, int $attemptNumber, array $context): ?int
    {
        // TODO: implement weighted rotation across healthy providers.
        // TODO: after 2 consecutive provider failures, force provider switch.
        // TODO: cap at 6 attempts before terminal failure.
        return null;
    }
}
