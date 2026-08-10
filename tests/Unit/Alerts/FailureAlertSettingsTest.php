<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Alerts;

use InvalidArgumentException;
use OneSMTP\Alerts\FailureAlertSettings;
use PHPUnit\Framework\TestCase;

final class FailureAlertSettingsTest extends TestCase
{
    public function test_advanced_destinations_and_rules_are_normalized_deterministically(): void
    {
        $settings = FailureAlertSettings::fromArray([
            'advanced_enabled' => true,
            'advanced_destinations' => "email:ops@example.test\nwebhook:https://hooks.example.test/alerts\nemail:ops@example.test",
            'escalation_failure_threshold' => 99,
        ]);

        self::assertTrue($settings->isAdvancedEnabled());
        self::assertSame([
            [
				'channel' => 'email',
				'target' => 'ops@example.test',
			],
            [
				'channel' => 'webhook',
				'target' => 'https://hooks.example.test/alerts',
			],
        ], $settings->getAdvancedDestinations());
        self::assertSame(6, $settings->getEscalationFailureThreshold());
        self::assertTrue($settings->shouldEscalate(['attempt' => 6]));
        self::assertFalse($settings->shouldEscalate([
			'attempt' => 1,
		]));
    }

    public function test_empty_or_invalid_destinations_fail_closed(): void
    {
        $settings = FailureAlertSettings::fromArray([
            'advanced_enabled' => true,
            'advanced_destinations' => '',
        ]);

        self::assertFalse($settings->isAdvancedEnabled());

        $this->expectException(InvalidArgumentException::class);
        FailureAlertSettings::fromArray([
            'advanced_enabled' => true,
            'advanced_destinations' => 'webhook:http://127.0.0.1/private',
        ]);
    }

    public function test_webhook_credentials_and_private_hosts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FailureAlertSettings::fromArray([
            'webhook_enabled' => true,
            'webhook_url' => 'https://user:password@hooks.example.test/alerts',
        ]);
    }

    public function test_runtime_webhook_validation_rejects_private_and_reserved_literal_targets(): void
    {
        foreach (['https://127.0.0.1/private', 'https://192.0.2.1/reserved', 'https://[::1]/loopback'] as $url) {
            self::assertFalse(FailureAlertSettings::isSafeWebhookUrl($url));
        }
    }

    public function test_runtime_webhook_validation_rejects_hostname_resolving_to_private_address(): void
    {
        $resolver = static fn (string $host): array => $host === 'hooks.example.test' ? ['10.0.0.7'] : [];

        self::assertFalse(FailureAlertSettings::isSafeWebhookUrl('https://hooks.example.test/alerts', $resolver));
    }
}
