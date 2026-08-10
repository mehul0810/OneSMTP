<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Security;

use OneSMTP\Security\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    /** @dataProvider signingKeyTextProvider */
    public function test_webhook_signing_keys_are_redacted_from_free_text(string $text): void
    {
        $redacted = (new Redactor())->redactText($text);

        self::assertStringNotContainsString('fixture-webhook-signing-key', $redacted);
        self::assertStringContainsString('[REDACTED]', $redacted);
    }

    /** @return array<string,array{0:string}> */
    public static function signingKeyTextProvider(): array
    {
        return [
            'equals' => ['webhook_signing_key=fixture-webhook-signing-key'],
            'json' => ['{"webhook_signing_key":"fixture-webhook-signing-key"}'],
            'hyphenated' => ['webhook-signing-key: fixture-webhook-signing-key'],
        ];
    }
}
