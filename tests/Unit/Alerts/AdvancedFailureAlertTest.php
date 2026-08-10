<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Alerts;

use OneSMTP\Alerts\FailureAlertDispatcher;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class AdvancedFailureAlertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_mail'] = [];
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_remote_posts'] = [];
        $GLOBALS['onesmtp_test_transients'] = [];
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_enabled_pro_escalation_sends_each_configured_destination_after_threshold(): void
    {
        update_option('onesmtp_settings', [
            'failure_alerts' => [
                'advanced_enabled' => true,
                'advanced_destinations' => [
                    [
						'channel' => 'email',
						'target' => 'ops@example.test',
					],
                    [
						'channel' => 'email',
						'target' => 'oncall@example.test',
					],
                    [
						'channel' => 'webhook',
						'target' => 'https://hooks.example.test/escalations',
					],
                ],
                'escalation_failure_threshold' => 3,
            ],
        ], false);

        $dispatcher = new FailureAlertDispatcher(null, null, new FeatureGate([
            FeatureGate::ADVANCED_ALERTS => true,
        ], true));
        $dispatcher->handleTerminalFailure([
			'attempt' => 3,
			'reason' => 'provider_timeout',
		], 12, 9, 100);

        self::assertCount(2, $GLOBALS['onesmtp_test_mail']);
        self::assertCount(1, $GLOBALS['onesmtp_test_remote_posts']);
        self::assertSame('oncall@example.test', $GLOBALS['onesmtp_test_mail'][1]['to'][0]);
        self::assertSame('https://hooks.example.test/escalations', $GLOBALS['onesmtp_test_remote_posts'][0]['url']);
        self::assertStringContainsString('"alert_level":"escalated"', (string) $GLOBALS['onesmtp_test_remote_posts'][0]['args']['body']);
    }

    public function test_free_or_unmatched_context_does_not_send_advanced_alerts(): void
    {
        update_option('onesmtp_settings', [
            'failure_alerts' => [
                'advanced_enabled' => true,
                'advanced_destinations' => [
                    [
						'channel' => 'email',
						'target' => 'ops@example.test',
					],
                ],
                'high_priority_message_types' => ['password_reset'],
            ],
        ], false);

        (new FailureAlertDispatcher())->handleTerminalFailure([
			'attempt' => 1,
			'message_type' => 'password_reset',
		], 12, 9, 101);
        self::assertSame([], $GLOBALS['onesmtp_test_mail']);

        $dispatcher = new FailureAlertDispatcher(null, null, new FeatureGate([
            FeatureGate::ADVANCED_ALERTS => true,
        ], true));
        $dispatcher->handleTerminalFailure([
			'attempt' => 1,
			'message_type' => 'newsletter',
		], 13, 9, 102);
        self::assertSame([], $GLOBALS['onesmtp_test_mail']);
    }

    public function test_escalation_has_its_own_throttle_and_is_not_hidden_by_core_alert_throttle(): void
    {
        update_option('onesmtp_settings', [
            'failure_alerts' => [
                'email_enabled' => true,
                'email_recipients' => ['core@example.test'],
                'advanced_enabled' => true,
                'advanced_destinations' => [
                    [
						'channel' => 'email',
						'target' => 'oncall@example.test',
					],
                ],
                'escalation_failure_threshold' => 3,
            ],
        ], false);

        $dispatcher = new FailureAlertDispatcher(null, null, new FeatureGate([
            FeatureGate::ADVANCED_ALERTS => true,
        ], true));
        $dispatcher->handleTerminalFailure([
			'attempt' => 1,
			'reason' => 'provider_timeout',
		], 21, 9, 110);
        $dispatcher->handleTerminalFailure([
			'attempt' => 3,
			'reason' => 'provider_timeout',
		], 21, 9, 111);

        self::assertCount(2, $GLOBALS['onesmtp_test_mail']);
        self::assertSame(['core@example.test'], $GLOBALS['onesmtp_test_mail'][0]['to']);
        self::assertSame(['oncall@example.test'], $GLOBALS['onesmtp_test_mail'][1]['to']);
    }

    public function test_terminal_failure_context_is_allowlisted_before_persistence(): void
    {
        (new EventRepository())->add('terminal_failure', [
            'attempt' => 3,
            'reason' => 'provider_timeout',
            'message_type' => 'password_reset',
            'raw_body' => 'private body',
            'recipients' => ['private@example.test'],
            'headers' => ['Authorization: Bearer secret-token'],
            'credentials' => 'provider-secret',
        ], 12, 9);

        $insert = $GLOBALS['wpdb']->inserts[0] ?? [];
        $json = (string) ($insert['data']['context_json'] ?? '');
        self::assertStringContainsString('password_reset', $json);
        self::assertStringNotContainsString('private body', $json);
        self::assertStringNotContainsString('private@example.test', $json);
        self::assertStringNotContainsString('secret-token', $json);
        self::assertStringNotContainsString('provider-secret', $json);
    }
}
