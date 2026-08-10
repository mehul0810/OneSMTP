<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Events;

use DateTimeImmutable;
use InvalidArgumentException;
use OneSMTP\Events\ProviderEvent;
use OneSMTP\Events\ProviderEventType;
use OneSMTP\Tests\Support\ProviderEventFixtures;
use PHPUnit\Framework\TestCase;

final class ProviderEventTest extends TestCase
{
    public function test_provider_values_normalize_to_bounded_neutral_types(): void
    {
        self::assertSame(ProviderEventType::DELIVERY, ProviderEventType::fromProviderValue('DELIVERED'));
        self::assertSame(ProviderEventType::BOUNCE, ProviderEventType::fromProviderValue('bounced'));
        self::assertSame(ProviderEventType::COMPLAINT, ProviderEventType::fromProviderValue('spam-complaint'));
        self::assertSame(ProviderEventType::DEFERRAL, ProviderEventType::fromProviderValue('temporary failure'));
        self::assertSame(ProviderEventType::UNKNOWN, ProviderEventType::fromProviderValue('provider-specific-future-state'));
    }

    public function test_only_bounce_and_complaint_are_suppression_signals(): void
    {
        self::assertFalse(ProviderEventType::DELIVERY->isSuppressionSignal());
        self::assertTrue(ProviderEventType::BOUNCE->isSuppressionSignal());
        self::assertTrue(ProviderEventType::COMPLAINT->isSuppressionSignal());
        self::assertFalse(ProviderEventType::DEFERRAL->isSuppressionSignal());
        self::assertFalse(ProviderEventType::UNKNOWN->isSuppressionSignal());
    }

    public function test_dto_exposes_only_normalized_non_raw_fields(): void
    {
        $event = ProviderEventFixtures::event('bounce', '002');
        $serialized = $event->toArray();

        self::assertSame(ProviderEventType::BOUNCE, $event->getType());
        self::assertSame('fixture-provider', $event->getProvider());
        self::assertSame('fixture-event-002', $event->getEventId());
        self::assertSame('synthetic-fixture', $event->getReasonCode());
        self::assertSame('bounce', $serialized['type']);
        self::assertArrayHasKey('recipient_fingerprint', $serialized);
        self::assertStringNotContainsString('Recipient@example.test', (string) wp_json_encode($serialized));
    }

    public function test_invalid_or_unbounded_event_fields_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProviderEvent(
            ProviderEventType::DELIVERY,
            'fixture-provider',
            'fixture-event-001',
            new DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            str_repeat('a', 64) . '0'
        );
    }
}
