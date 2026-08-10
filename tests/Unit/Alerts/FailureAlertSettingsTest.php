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
            'high_priority_message_types' => "password_reset\norder_update\npassword_reset",
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
        self::assertSame(['password_reset', 'order_update'], $settings->getHighPriorityMessageTypes());
        self::assertTrue($settings->shouldEscalate(['attempt' => 6]));
        self::assertTrue($settings->shouldEscalate([
			'attempt' => 1,
			'message_type' => 'password_reset',
		]));
        self::assertFalse($settings->shouldEscalate([
			'attempt' => 1,
			'message_type' => 'newsletter',
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
}
