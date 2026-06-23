<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Settings;

use InvalidArgumentException;
use OneSMTP\Settings\SenderIdentity;
use PHPUnit\Framework\TestCase;

final class SenderIdentityTest extends TestCase
{
    public function test_from_array_sanitizes_addresses_and_splits_lists(): void
    {
        $identity = SenderIdentity::fromArray([
            'from_email' => ' sender@example.test ',
            'from_name' => '<b>Example Sender</b>',
            'reply_to' => "reply@example.test\nsecond@example.test",
            'bcc' => 'audit@example.test, archive@example.test, audit@example.test',
            'force_from_email' => '1',
            'force_reply_to' => true,
        ]);

        self::assertSame('sender@example.test', $identity->getFromEmail());
        self::assertSame('Example Sender', $identity->getFromName());
        self::assertSame(['reply@example.test', 'second@example.test'], $identity->getReplyTo());
        self::assertSame(['audit@example.test', 'archive@example.test'], $identity->getBcc());
        self::assertTrue($identity->shouldForceFromEmail());
        self::assertTrue($identity->shouldForceReplyTo());
        self::assertFalse($identity->shouldForceFromName());
    }

    public function test_invalid_configured_email_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('From Email must be a valid email address.');

        SenderIdentity::fromArray(['from_email' => 'not-an-email']);
    }

    public function test_invalid_list_email_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reply-To contains an invalid email address.');

        SenderIdentity::fromArray(['reply_to' => ['valid@example.test', 'bad-address']]);
    }
}
