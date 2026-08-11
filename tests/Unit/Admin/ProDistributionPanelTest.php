<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\ProDistributionPanel;
use OneSMTP\Product\Licensing\LicenseClient;
use OneSMTP\Product\Licensing\LicenseState;
use OneSMTP\Product\Licensing\LicenseStatus;
use OneSMTP\Product\Licensing\MaskedIdentifier;
use OneSMTP\Product\Licensing\UpdateState;
use OneSMTP\Product\Licensing\UpdateStatus;
use OneSMTP\Tests\Support\FakeLicenseClient;
use OneSMTP\Tests\Support\FakeUpdateProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProDistributionPanelTest extends TestCase
{
    public function test_default_panel_honestly_reports_no_connected_services(): void
    {
        $output = $this->render(new ProDistributionPanel());

        self::assertStringContainsString('Pro distribution foundation', $output);
        self::assertSame(2, substr_count($output, 'Not configured'));
        self::assertStringContainsString('No license service, activation request, update feed, package download, or purchase flow is connected.', $output);
        self::assertStringNotContainsString('<form', $output);
        self::assertStringNotContainsString('type="password"', $output);
    }

    public function test_panel_renders_only_a_masked_identifier(): void
    {
        $raw = 'long-private-license-value-Z9X8';
        $panel = new ProDistributionPanel(
            new FakeLicenseClient(LicenseStatus::create(
                LicenseState::ACTIVE,
                MaskedIdentifier::fromRaw($raw),
                'active'
            )),
            new FakeUpdateProvider(UpdateStatus::create(UpdateState::CURRENT, 'current'))
        );
        $output = $this->render($panel);

        self::assertStringContainsString('****Z9X8', $output);
        self::assertStringNotContainsString($raw, $output);
        self::assertStringContainsString('Current', $output);
    }

    public function test_client_failure_renders_a_bounded_error_state(): void
    {
        $client = new class() implements LicenseClient {
            public function status(): LicenseStatus
            {
                throw new RuntimeException('private remote diagnostic');
            }

            public function activate(string $licenseKey): LicenseStatus
            {
                throw new RuntimeException('unused');
            }

            public function deactivate(): LicenseStatus
            {
                throw new RuntimeException('unused');
            }

            public function refresh(): LicenseStatus
            {
                throw new RuntimeException('unused');
            }
        };

        $output = $this->render(new ProDistributionPanel($client));

        self::assertStringContainsString('Status unavailable', $output);
        self::assertStringNotContainsString('private remote diagnostic', $output);
    }

    private function render(ProDistributionPanel $panel): string
    {
        ob_start();
        $panel->render();

        return (string) ob_get_clean();
    }
}
