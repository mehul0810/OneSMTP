<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Pipeline;

use OneSMTP\Pipeline\SenderIdentityApplier;
use OneSMTP\Settings\SenderIdentity;
use PHPUnit\Framework\TestCase;

final class SenderIdentityApplierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_options'] = [];
    }

    public function test_apply_adds_configured_headers_when_message_has_none(): void
    {
        $applier = $this->applierFor(new SenderIdentity(
            'sender@example.test',
            'Example Sender',
            ['reply@example.test'],
            ['audit@example.test']
        ));

        $args = $applier->apply([
            'to' => ['customer@example.test'],
            'subject' => 'Subject',
            'message' => 'Body',
            'headers' => [],
        ]);

        self::assertContains('From: "Example Sender" <sender@example.test>', $args['headers']);
        self::assertContains('Reply-To: reply@example.test', $args['headers']);
        self::assertContains('Bcc: audit@example.test', $args['headers']);
    }

    public function test_apply_leaves_headers_untouched_without_configured_identity(): void
    {
        $applier = new SenderIdentityApplier();
        $args = [
            'headers' => "X-Example: one\r\nX-Other: two",
        ];

        self::assertSame($args, $applier->apply($args));
    }

    public function test_apply_preserves_existing_headers_until_force_is_enabled(): void
    {
        $applier = $this->applierFor(new SenderIdentity(
            'configured@example.test',
            'Configured Name',
            ['configured-reply@example.test'],
            ['configured-bcc@example.test']
        ));

        $args = $applier->apply([
            'headers' => [
                'From: "Existing Name" <existing@example.test>',
                'Reply-To: existing-reply@example.test',
                'Bcc: existing-bcc@example.test',
            ],
        ]);

        self::assertContains('From: "Existing Name" <existing@example.test>', $args['headers']);
        self::assertContains('Reply-To: existing-reply@example.test', $args['headers']);
        self::assertContains('Bcc: existing-bcc@example.test', $args['headers']);
        self::assertNotContains('From: "Configured Name" <configured@example.test>', $args['headers']);
    }

    public function test_apply_replaces_existing_headers_when_force_is_enabled(): void
    {
        $applier = $this->applierFor(new SenderIdentity(
            'configured@example.test',
            'Configured Name',
            ['configured-reply@example.test'],
            ['configured-bcc@example.test'],
            true,
            true,
            true,
            true
        ));

        $args = $applier->apply([
            'headers' => [
                'From: "Existing Name" <existing@example.test>',
                'Reply-To: existing-reply@example.test',
                'Bcc: existing-bcc@example.test',
            ],
        ]);

        self::assertContains('From: "Configured Name" <configured@example.test>', $args['headers']);
        self::assertContains('Reply-To: configured-reply@example.test', $args['headers']);
        self::assertContains('Bcc: configured-bcc@example.test', $args['headers']);
        self::assertNotContains('From: "Existing Name" <existing@example.test>', $args['headers']);
    }

    public function test_force_from_name_only_preserves_existing_email(): void
    {
        $applier = $this->applierFor(new SenderIdentity(
            'configured@example.test',
            'Configured Name',
            [],
            [],
            false,
            true
        ));

        $args = $applier->apply([
            'headers' => ['From: "Existing Name" <existing@example.test>'],
        ]);

        self::assertContains('From: "Configured Name" <existing@example.test>', $args['headers']);
    }
    private function applierFor(SenderIdentity $identity): SenderIdentityApplier
    {
        update_option('onesmtp_settings', ['sender_identity' => $identity->toArray()], false);

        return new SenderIdentityApplier();
    }
}
