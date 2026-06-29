<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Logging;

use OneSMTP\Logging\AttachmentLogSanitizer;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class AttachmentLogSanitizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
    }

    public function test_default_state_removes_raw_attachment_paths_from_stored_payload(): void
    {
        $payload = (new AttachmentLogSanitizer())->sanitizePayload([
            'to' => ['person@example.test'],
            'attachments' => [
                '/private/var/tmp/customer-contract.pdf',
                '/srv/site/wp-content/uploads/invoice.csv',
            ],
        ]);

        self::assertArrayNotHasKey('attachments', $payload);
        self::assertArrayNotHasKey(AttachmentLogSanitizer::PAYLOAD_KEY, $payload);
        self::assertStringNotContainsString('/private/var/tmp', (string) wp_json_encode($payload));
        self::assertStringNotContainsString('/srv/site', (string) wp_json_encode($payload));
    }

    public function test_enabled_state_stores_bounded_metadata_without_raw_paths(): void
    {
        update_option('onesmtp_settings', [
            'attachment_logging' => [
                'enabled' => true,
            ],
        ], false);

        $payload = (new AttachmentLogSanitizer())->sanitizePayload([
            'attachments' => [
                '/private/var/tmp/customer-contract.pdf',
                '/srv/site/wp-content/uploads/Q2 revenue.xlsx',
            ],
        ]);
        $log = $payload[AttachmentLogSanitizer::PAYLOAD_KEY] ?? [];
        $json = (string) wp_json_encode($payload);

        self::assertArrayNotHasKey('attachments', $payload);
        self::assertSame(2, $log['count'] ?? null);
        self::assertFalse($log['truncated'] ?? true);
        self::assertSame('customer-contract.pdf', $log['items'][0]['filename'] ?? null);
        self::assertSame('Q2-revenue.xlsx', $log['items'][1]['filename'] ?? null);
        self::assertSame('pdf', $log['items'][0]['extension'] ?? null);
        self::assertStringNotContainsString('/private/var/tmp', $json);
        self::assertStringNotContainsString('/srv/site', $json);
    }
}
