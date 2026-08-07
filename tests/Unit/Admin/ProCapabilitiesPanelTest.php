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
        self::assertStringContainsString('Core sending, failover, queues, and logs remain available without Pro.', $output);
    }

    public function test_enabled_feature_is_not_rendered_as_a_disabled_control(): void
    {
        $panel = new ProCapabilitiesPanel(new FeatureGate([FeatureGate::SMART_ROUTING => true], true));

        ob_start();
        $panel->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('Smart routing rules', $output);
        self::assertStringContainsString('Enabled', $output);
        self::assertSame(4, substr_count($output, 'disabled aria-disabled="true"'));
    }
}
