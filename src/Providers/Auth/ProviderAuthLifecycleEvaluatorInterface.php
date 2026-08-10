<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

interface ProviderAuthLifecycleEvaluatorInterface
{
    public function evaluate(ProviderAuthContext $context): ProviderAuthStatus;
}
