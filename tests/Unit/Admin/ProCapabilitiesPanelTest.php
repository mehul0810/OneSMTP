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
        self::assertStringContainsString('Planned', $output);
        self::assertStringContainsString('Not available yet', $output);
        self::assertStringContainsString('disabled aria-disabled="true"', $output);
        self::assertStringContainsString('Core sending, providers, failover, queues, and logs remain available without Pro.', $output);
        self::assertSame(5, substr_count($output, 'Requires Pro'));
        self::assertSame(1, substr_count($output, 'Not available yet'));
    }

    public function test_enabled_feature_is_not_rendered_as_a_disabled_control(): void
    {
        $panel = new ProCapabilitiesPanel(new FeatureGate([FeatureGate::SMART_ROUTING => true], true));

        ob_start();
        $panel->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Smart routing rules', $output);
        self::assertStringContainsString('Enabled', $output);
        self::assertSame(5, substr_count($output, 'disabled aria-disabled="true"'));
    }

    public function test_planned_catalog_entries_remain_inert_even_when_flags_are_supplied(): void
    {
        $panel = new ProCapabilitiesPanel(new FeatureGate([
            FeatureGate::PROVIDER_EVENTS => true,
        ], true));

        ob_start();
        $panel->render();
        $output = (string) ob_get_clean();

        self::assertSame(1, substr_count($output, 'Planned'));
        self::assertSame(1, substr_count($output, 'Not available yet'));
        self::assertStringNotContainsString('>Enabled</span>', $output);
    }
}
