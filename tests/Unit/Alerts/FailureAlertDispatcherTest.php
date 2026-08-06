<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Alerts;

use OneSMTP\Repository\EventRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class FailureAlertDispatcherTest extends TestCase
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

    public function test_throttle_prevents_repeated_failure_alert_floods(): void
    {
        update_option('onesmtp_settings', [
            'failure_alerts' => [
                'email_enabled' => true,
                'email_recipients' => ['ops@example.test'],
                'webhook_enabled' => true,
                'webhook_url' => 'https://hooks.example.test/onesmtp',
                'throttle_seconds' => 900,
            ],
        ], false);

        $events = new EventRepository();
        $context = ['attempt' => 6, 'reason' => 'retry_backend_unavailable', 'failure_category' => 'terminal'];

        $events->add('terminal_failure', $context, 101, 5);
        $events->add('terminal_failure', $context, 101, 5);

        self::assertCount(1, $GLOBALS['onesmtp_test_mail']);
        self::assertCount(1, $GLOBALS['onesmtp_test_remote_posts']);
        self::assertCount(1, $GLOBALS['onesmtp_test_transients']);
    }

    public function test_disabled_or_empty_configuration_sends_no_alert(): void
    {
        update_option('onesmtp_settings', [
            'failure_alerts' => [
                'email_enabled' => true,
                'email_recipients' => [],
                'webhook_enabled' => true,
                'webhook_url' => '',
            ],
        ], false);

        (new EventRepository())->add('terminal_failure', ['reason' => 'missing_provider'], 202, null);

        self::assertSame([], $GLOBALS['onesmtp_test_mail']);
        self::assertSame([], $GLOBALS['onesmtp_test_remote_posts']);
    }
}
