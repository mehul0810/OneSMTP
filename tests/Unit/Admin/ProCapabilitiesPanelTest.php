<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\ProCapabilitiesPanel;
use OneSMTP\Product\FeatureGate;
use PHPUnit\Framework\TestCase;

final class ProCapabilitiesPanelTest extends TestCase
{
    public function test_free_state_explains_disabled_pro_controls(): void
    {
        $panel = new ProCapabilitiesPanel(new FeatureGate());

        ob_start();
        $panel->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Pro capabilities', $output);
        self::assertStringContainsString('Available with Pro', $output);
        self::assertStringContainsString('Requires Pro', $output);
        self::assertStringContainsString('disabled aria-disabled="true"', $output);
        self::assertStringContainsString('Core sending, providers, failover, queues, and logs remain available without Pro.', $output);
        self::assertSame(7, substr_count($output, 'Requires Pro'));
    }

    public function test_enabled_feature_is_not_rendered_as_a_disabled_control(): void
    {
        $panel = new ProCapabilitiesPanel(new FeatureGate([FeatureGate::SMART_ROUTING => true], true));

        ob_start();
        $panel->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Smart routing rules', $output);
        self::assertStringContainsString('Enabled', $output);
        self::assertStringContainsString('Provider sending budgets', $output);
        self::assertSame(6, substr_count($output, 'disabled aria-disabled="true"'));
    }

    public function test_provider_event_ingestion_is_enabled_when_the_gate_is_supplied(): void
    {
        $panel = new ProCapabilitiesPanel(new FeatureGate([
            FeatureGate::PROVIDER_EVENTS => true,
        ], true));

        ob_start();
        $panel->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Provider events and suppression', $output);
        self::assertStringContainsString('>Enabled</span>', $output);
        self::assertStringContainsString('suppression controls remain planned.', $output);
    }
}
