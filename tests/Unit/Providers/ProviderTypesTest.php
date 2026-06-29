<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Providers;

use OneSMTP\Providers\ProviderTypes;
use PHPUnit\Framework\TestCase;

final class ProviderTypesTest extends TestCase
{
    public function test_every_supported_provider_declares_capability_metadata(): void
    {
        $metadata = ProviderTypes::metadata();
        $capabilities = array_keys(ProviderTypes::capabilityLabels());

        self::assertEqualsCanonicalizing(ProviderTypes::all(), array_keys($metadata));

        foreach (ProviderTypes::all() as $type) {
            self::assertNotSame('', $metadata[$type]['label']);
            self::assertNotSame('', $metadata[$type]['description']);
            self::assertEqualsCanonicalizing($capabilities, array_keys($metadata[$type]['capabilities']));

            foreach ($metadata[$type]['capabilities'] as $available) {
                self::assertIsBool($available);
            }
        }
    }

    public function test_provider_capability_metadata_marks_mvp_delivery_paths(): void
    {
        $metadata = ProviderTypes::metadata();

        self::assertTrue($metadata[ProviderTypes::SMTP]['capabilities']['smtp']);
        self::assertFalse($metadata[ProviderTypes::SMTP]['capabilities']['api_delivery']);
        self::assertFalse($metadata[ProviderTypes::PHP_MAIL]['capabilities']['smtp']);
        self::assertTrue($metadata[ProviderTypes::GMAIL]['capabilities']['oauth']);

        foreach ([ProviderTypes::GMAIL, ProviderTypes::SENDGRID, ProviderTypes::POSTMARK, ProviderTypes::BREVO] as $type) {
            self::assertTrue($metadata[$type]['capabilities']['api_delivery']);
            self::assertTrue($metadata[$type]['capabilities']['provider_message_id']);
        }
    }
}
