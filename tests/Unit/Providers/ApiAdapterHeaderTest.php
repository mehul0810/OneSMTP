<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Providers;

use OneSMTP\Providers\Adapters\BrevoAdapter;
use OneSMTP\Providers\Adapters\PostmarkAdapter;
use OneSMTP\Providers\Adapters\SendGridAdapter;
use OneSMTP\Providers\ProviderConfig;
use PHPUnit\Framework\TestCase;

final class ApiAdapterHeaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_remote_posts'] = [];
        $GLOBALS['onesmtp_test_remote_response'] = [
            'response' => ['code' => 202],
            'body' => '{"messageId":"provider-message-id","MessageID":"provider-message-id"}',
        ];
    }

    public function test_sendgrid_payload_includes_reply_to_and_bcc_headers(): void
    {
        $result = (new SendGridAdapter())->send($this->message(), new ProviderConfig(['api_key' => 'test-key']));

        self::assertTrue($result->isSuccess());
        $payload = $this->lastPayload();

        self::assertSame(['email' => 'reply@example.test'], $payload['reply_to']);
        self::assertSame(
            [['email' => 'audit@example.test'], ['email' => 'archive@example.test']],
            $payload['personalizations'][0]['bcc']
        );
    }

    public function test_brevo_payload_includes_reply_to_and_bcc_headers(): void
    {
        $result = (new BrevoAdapter())->send($this->message(), new ProviderConfig(['api_key' => 'test-key']));

        self::assertTrue($result->isSuccess());
        $payload = $this->lastPayload();

        self::assertSame(['email' => 'reply@example.test'], $payload['replyTo']);
        self::assertSame(
            [['email' => 'audit@example.test'], ['email' => 'archive@example.test']],
            $payload['bcc']
        );
    }

    public function test_postmark_payload_includes_reply_to_and_bcc_headers(): void
    {
        $result = (new PostmarkAdapter())->send($this->message(), new ProviderConfig(['api_key' => 'test-key']));

        self::assertTrue($result->isSuccess());
        $payload = $this->lastPayload();

        self::assertSame('reply@example.test', $payload['ReplyTo']);
        self::assertSame('audit@example.test,archive@example.test', $payload['Bcc']);
    }

    private function message(): array
    {
        return [
            'to' => ['customer@example.test'],
            'subject' => 'Subject',
            'message' => 'Body',
            'headers' => [
                'From: "Example Sender" <sender@example.test>',
                'Reply-To: "Reply Team" <reply@example.test>',
                'Bcc: audit@example.test, archive@example.test',
            ],
        ];
    }

    private function lastPayload(): array
    {
        $last = end($GLOBALS['onesmtp_test_remote_posts']);
        self::assertIsArray($last);

        $payload = json_decode((string) ($last['args']['body'] ?? ''), true);
        self::assertIsArray($payload);

        return $payload;
    }
}
