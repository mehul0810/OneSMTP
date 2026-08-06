<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\AlertHistoryAdmin;
use OneSMTP\Core\Capabilities;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class AlertHistoryAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            Capabilities::MANAGE_PLUGIN => true,
        ];
        $GLOBALS['onesmtp_test_nonce_valid'] = true;
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['onesmtp_test_current_user_caps'],
            $GLOBALS['onesmtp_test_current_user_can'],
            $GLOBALS['onesmtp_test_nonce_valid'],
            $GLOBALS['onesmtp_test_redirect']
        );
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        parent::tearDown();
    }

    public function test_render_handles_empty_and_success_states(): void
    {
        $_GET['onesmtp_alert_history_status'] = 'acknowledged';

        ob_start();
        (new AlertHistoryAdmin())->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Alert event acknowledged.', $html);
        self::assertStringContainsString('No alert events have been recorded yet.', $html);
    }

    public function test_render_displays_long_sanitized_context_without_sensitive_payloads(): void
    {
        $GLOBALS['wpdb']->eventRows = [
            22 => [
                'id' => 22,
                'event_type' => 'terminal_failure',
                'actor_id' => 0,
                'message_id' => 123,
                'provider_id' => 9,
                'context_json' => wp_json_encode([
                    'reason' => 'provider_error',
                    'summary' => str_repeat('Provider timeout ', 40) . 'token=private-token',
                    'headers' => 'Authorization: Bearer raw-header-token',
                ]),
                'created_at' => '2026-07-01 12:00:00',
            ],
        ];

        ob_start();
        (new AlertHistoryAdmin())->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('#22', $html);
        self::assertStringContainsString('textarea', $html);
        self::assertStringContainsString('[REDACTED]', $html);
        self::assertStringNotContainsString('private-token', $html);
        self::assertStringNotContainsString('raw-header-token', $html);
    }

    public function test_acknowledge_requires_manage_capability(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            Capabilities::MANAGE_PLUGIN => false,
        ];
        $this->postAcknowledge(33);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to acknowledge Aculect Mail alerts.');

        (new AlertHistoryAdmin())->handleRequest();
    }

    public function test_acknowledge_requires_valid_nonce(): void
    {
        $GLOBALS['onesmtp_test_nonce_valid'] = false;
        $this->postAcknowledge(33);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Aculect Mail alert acknowledgement link has expired.');

        (new AlertHistoryAdmin())->handleRequest();
    }

    public function test_acknowledge_persists_and_redirects(): void
    {
        $GLOBALS['wpdb']->eventRows = [
            33 => [
                'id' => 33,
                'event_type' => 'terminal_failure',
                'actor_id' => 0,
                'message_id' => 14,
                'provider_id' => 2,
                'context_json' => wp_json_encode(['reason' => 'missing_provider']),
                'created_at' => '2026-07-01 13:00:00',
            ],
        ];
        $this->postAcknowledge(33);

        try {
            (new AlertHistoryAdmin())->handleRequest();
            self::fail('Expected testing redirect exception.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Aculect Mail alert history redirected.', $exception->getMessage());
        }

        self::assertCount(1, $GLOBALS['wpdb']->inserts);
        self::assertSame('audit_alert_acknowledged', $GLOBALS['wpdb']->inserts[0]['data']['event_type']);
        self::assertStringContainsString('onesmtp_alert_history_status=acknowledged', $GLOBALS['onesmtp_test_redirect']['location']);
    }

    private function postAcknowledge(int $eventId): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_alert_history_action' => 'acknowledge',
            'onesmtp_alert_event_id' => (string) $eventId,
            'onesmtp_alert_history_nonce' => 'test-nonce',
        ];
    }
}
