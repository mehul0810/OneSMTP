<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Conflict;

use OneSMTP\Conflict\MailConflictDetector;
use PHPUnit\Framework\TestCase;

final class MailConflictDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['wp_filter'] = [];
    }

    public function test_detect_returns_empty_state_when_no_conflicts_exist(): void
    {
        $detector = new MailConflictDetector();

        self::assertSame(['plugins' => [], 'hooks' => []], $detector->detect());
    }

    public function test_detect_reports_known_active_mail_plugins_without_paths(): void
    {
        update_option('active_plugins', [
            'wp-mail-smtp/wp_mail_smtp.php',
            'akismet/akismet.php',
            'post-smtp/postman-smtp.php',
        ]);

        $detector = new MailConflictDetector();

        self::assertSame(['Post SMTP', 'WP Mail SMTP'], $detector->detect()['plugins']);
    }

    public function test_detect_reports_mail_hook_counts_without_mutating_callbacks(): void
    {
        $callback = static fn (): bool => true;
        $GLOBALS['wp_filter']['phpmailer_init'] = [
            10 => [
                'external_callback' => [
                    'function' => $callback,
                ],
            ],
        ];
        $GLOBALS['wp_filter']['wp_mail'] = [
            10 => [
                'external_mail_callback' => [
                    'function' => 'external_mail_callback',
                ],
            ],
        ];

        $detector = new MailConflictDetector();
        $result = $detector->detect();

        self::assertSame(['wp_mail' => 1, 'phpmailer_init' => 1], $result['hooks']);
        self::assertSame($callback, $GLOBALS['wp_filter']['phpmailer_init'][10]['external_callback']['function']);
    }

}
