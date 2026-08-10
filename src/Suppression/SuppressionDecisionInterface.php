<?php

declare(strict_types=1);

namespace OneSMTP\Suppression;

use OneSMTP\Events\ProviderEvent;

interface SuppressionDecisionInterface
{
    public function decide(ProviderEvent $event): SuppressionDecision;
}
