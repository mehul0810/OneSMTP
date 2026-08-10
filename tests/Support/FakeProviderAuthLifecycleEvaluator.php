<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Support;

use OneSMTP\Providers\Auth\ProviderAuthContext;
use OneSMTP\Providers\Auth\ProviderAuthCapabilities;
use OneSMTP\Providers\Auth\ProviderAuthLifecycleEvaluatorInterface;
use OneSMTP\Providers\Auth\ProviderAuthState;
use OneSMTP\Providers\Auth\ProviderAuthStatus;

/**
 * Injectable, side-effect-free evaluator double for future consumers.
 */
final class FakeProviderAuthLifecycleEvaluator implements ProviderAuthLifecycleEvaluatorInterface
{
    public int $evaluationCount = 0;

    private ProviderAuthStatus $status;

    public function __construct(?ProviderAuthStatus $status = null)
    {
        $this->status = $status ?? new ProviderAuthStatus(ProviderAuthState::UNKNOWN, ProviderAuthCapabilities::none());
    }

    public function evaluate(ProviderAuthContext $context): ProviderAuthStatus
    {
        ++$this->evaluationCount;

        return $this->status;
    }
}
