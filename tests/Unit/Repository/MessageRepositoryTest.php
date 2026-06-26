<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Repository;

use OneSMTP\Logging\AttachmentLogSanitizer;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class MessageRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
    }

    public function test_create_strips_attachments_from_default_stored_payload(): void
    {
        $messageId = (new MessageRepository())->create([
            'to' => ['person@example.test'],
            'subject' => 'Attachment test',
            'message' => 'Body',
            'attachments' => ['/private/tmp/secret.pdf'],
        ], 6, 'message-uuid');

        self::assertSame(1, $messageId);
        $payload = json_decode((string) $GLOBALS['wpdb']->inserts[0]['data']['payload_json'], true);

        self::assertIsArray($payload);
        self::assertArrayNotHasKey('attachments', $payload);
        self::assertArrayNotHasKey(AttachmentLogSanitizer::PAYLOAD_KEY, $payload);
    }

    public function test_update_payload_stores_attachment_metadata_only_when_enabled(): void
    {
        update_option('onesmtp_settings', [
            'attachment_logging' => [
                'enabled' => true,
            ],
        ], false);

        (new MessageRepository())->updatePayload(55, [
            'to' => ['person@example.test'],
            'attachments' => ['/private/tmp/invoice.pdf'],
        ]);

        $payload = json_decode((string) $GLOBALS['wpdb']->updates[0]['data']['payload_json'], true);

        self::assertIsArray($payload);
        self::assertArrayNotHasKey('attachments', $payload);
        self::assertSame(1, $payload[AttachmentLogSanitizer::PAYLOAD_KEY]['count'] ?? null);
        self::assertSame('invoice.pdf', $payload[AttachmentLogSanitizer::PAYLOAD_KEY]['items'][0]['filename'] ?? null);
        self::assertStringNotContainsString('/private/tmp', (string) $GLOBALS['wpdb']->updates[0]['data']['payload_json']);
    }
}
