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
        self::assertSame(ProviderEventType::DELIVERED, ProviderEventType::fromProviderValue('DELIVERED'));
        self::assertSame(ProviderEventType::HARD_BOUNCE, ProviderEventType::fromProviderValue('permanent_bounce'));
        self::assertSame(ProviderEventType::SOFT_BOUNCE, ProviderEventType::fromProviderValue('temporary-bounce'));
        self::assertSame(ProviderEventType::COMPLAINT, ProviderEventType::fromProviderValue('spam-complaint'));
        self::assertSame(ProviderEventType::DEFERRED, ProviderEventType::fromProviderValue('temporary failure'));
        self::assertSame(ProviderEventType::UNKNOWN, ProviderEventType::fromProviderValue('provider-specific-future-state'));

        foreach (['accepted', 'sent', 'queued', 'bounce'] as $ambiguousValue) {
            self::assertSame(ProviderEventType::UNKNOWN, ProviderEventType::fromProviderValue($ambiguousValue), $ambiguousValue);
        }
    }

    public function test_only_hard_bounce_and_complaint_are_suppression_signals(): void
    {
        self::assertFalse(ProviderEventType::DELIVERED->isSuppressionSignal());
        self::assertTrue(ProviderEventType::HARD_BOUNCE->isSuppressionSignal());
        self::assertFalse(ProviderEventType::SOFT_BOUNCE->isSuppressionSignal());
        self::assertTrue(ProviderEventType::COMPLAINT->isSuppressionSignal());
        self::assertFalse(ProviderEventType::DEFERRED->isSuppressionSignal());
        self::assertFalse(ProviderEventType::UNKNOWN->isSuppressionSignal());
    }

    public function test_dto_exposes_only_normalized_non_raw_fields(): void
    {
        $event = ProviderEventFixtures::event('hard_bounce', '002');
        $serialized = $event->toArray();

        self::assertSame(ProviderEventType::HARD_BOUNCE, $event->getType());
        self::assertSame('fixture-provider', $event->getProvider());
        self::assertSame('fixture-event-002', $event->getEventId());
        self::assertSame('hard_bounce', $serialized['type']);
        self::assertArrayHasKey('recipient_fingerprint', $serialized);
        self::assertSame(
            ['type', 'provider', 'event_id', 'occurred_at', 'recipient_fingerprint', 'provider_message_id'],
            array_keys($serialized)
        );
        self::assertStringNotContainsString('Recipient@example.test', (string) wp_json_encode($serialized));
    }

    public function test_invalid_or_unbounded_event_fields_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProviderEvent(
            ProviderEventType::DELIVERED,
            'fixture-provider',
            'fixture-event-001',
            new DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            str_repeat('a', 64) . '0'
        );
    }
}
