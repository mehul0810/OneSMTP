<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Analytics;

use OneSMTP\Analytics\SubjectGroupFormatter;
use PHPUnit\Framework\TestCase;

final class SubjectGroupFormatterTest extends TestCase
{
    public function test_subject_label_is_normalized_redacted_and_bounded(): void
    {
        $formatter = new SubjectGroupFormatter();

        $label = $formatter->label("  Reset <b>password</b> token=secret-value\n" . str_repeat('x', 120));

        self::assertStringContainsString('Reset password token=[REDACTED]', $label);
        self::assertLessThanOrEqual(83, strlen($label));
        self::assertStringNotContainsString('<b>', $label);
        self::assertStringNotContainsString('secret-value', $label);
    }

    public function test_empty_subject_has_stable_safe_label_and_case_insensitive_key(): void
    {
        $formatter = new SubjectGroupFormatter();

        self::assertSame('No subject', $formatter->label(null));
        self::assertSame('No subject', $formatter->label(''));
        self::assertSame($formatter->key('Invoice update'), $formatter->key(' invoice   update '));
    }
}
