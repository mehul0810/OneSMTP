<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Logging;

use OneSMTP\Logging\RetentionPruner;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class RetentionPrunerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
        update_option('onesmtp_log_retention_days', 45, false);
        $GLOBALS['onesmtp_test_filters'] = [];
    }

    public function test_prune_deletes_parent_message_rows_that_hold_attachment_metadata(): void
    {
        (new RetentionPruner())->prune();

        self::assertCount(3, $GLOBALS['wpdb']->queries);
        self::assertStringContainsString('wp_onesmtp_attempts', $GLOBALS['wpdb']->queries[0]);
        self::assertStringContainsString('wp_onesmtp_events', $GLOBALS['wpdb']->queries[1]);
        self::assertStringContainsString('wp_onesmtp_messages', $GLOBALS['wpdb']->queries[2]);
        self::assertStringContainsString("status IN ('sent','failed','simulated')", $GLOBALS['wpdb']->queries[2]);
    }
}
