<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Security;

use InvalidArgumentException;
use OneSMTP\Security\RecipientNormalizer;
use OneSMTP\Security\SiteSecretHmac;
use PHPUnit\Framework\TestCase;

final class RecipientNormalizerTest extends TestCase
{
    public function test_recipient_normalization_is_deterministic_and_case_normalized(): void
    {
        $normalizer = new RecipientNormalizer();

        self::assertSame('recipient@example.test', $normalizer->normalize('  Recipient@Example.Test '));
        self::assertSame('recipient@example.test', $normalizer->normalize('Display Name <Recipient@Example.Test>'));
    }

    public function test_malformed_multiple_or_unbounded_recipients_are_rejected(): void
    {
        $normalizer = new RecipientNormalizer();

        foreach ([
            '',
            'not-an-email',
            'first@example.test,second@example.test',
            "recipient@example.test\nX-Injected: yes",
            str_repeat('a', 321) . '@example.test',
        ] as $recipient) {
            self::assertNull($normalizer->normalize($recipient));
        }
    }

    public function test_site_secret_hmac_is_context_bound_and_tamper_evident(): void
    {
        $hmac = new SiteSecretHmac('fixture-site-secret-without-production-data');
        $digest = $hmac->digest('recipient@example.test');

        self::assertSame(64, strlen($digest));
        self::assertTrue($hmac->verify('recipient@example.test', $digest));
        self::assertFalse($hmac->verify('other@example.test', $digest));
        self::assertFalse($hmac->verify('recipient@example.test', $hmac->digest('recipient@example.test', 'other-context')));
        self::assertFalse($hmac->verify('recipient@example.test', 'not-a-digest'));
    }

    public function test_empty_secret_or_context_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SiteSecretHmac('');
    }
}
