<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Product;

use OneSMTP\Product\FeatureGate;
use PHPUnit\Framework\TestCase;

final class FeatureGateTest extends TestCase
{
    public function test_free_installation_denies_every_pro_feature_by_default(): void
    {
        $gate = new FeatureGate();

        foreach (FeatureGate::featureIds() as $feature) {
            self::assertFalse($gate->isEnabled($feature));
            self::assertSame(FeatureGate::STATE_UPGRADE_REQUIRED, $gate->state($feature));
        }
    }

    public function test_entitlement_does_not_enable_a_feature_without_its_rollout_flag(): void
    {
        $gate = new FeatureGate([], true);

        self::assertFalse($gate->isEnabled(FeatureGate::SMART_ROUTING));
        self::assertSame(FeatureGate::STATE_FLAG_DISABLED, $gate->state(FeatureGate::SMART_ROUTING));
    }

    public function test_feature_requires_both_entitlement_and_rollout_flag(): void
    {
        $flaggedWithoutEntitlement = new FeatureGate([FeatureGate::SMART_ROUTING => true]);
        $enabled = new FeatureGate([FeatureGate::SMART_ROUTING => true], true);

        self::assertFalse($flaggedWithoutEntitlement->isEnabled(FeatureGate::SMART_ROUTING));
        self::assertTrue($enabled->isEnabled(FeatureGate::SMART_ROUTING));
        self::assertSame(FeatureGate::STATE_ENABLED, $enabled->state(FeatureGate::SMART_ROUTING));
    }

    public function test_unknown_feature_ids_fail_closed(): void
    {
        $gate = new FeatureGate(['unknown' => true], true);

        self::assertFalse($gate->isEnabled('unknown'));
        self::assertSame(FeatureGate::STATE_UNKNOWN, $gate->state('unknown'));
    }
}
