<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Support;

use DateTimeImmutable;
use OneSMTP\Events\ProviderEvent;
use OneSMTP\Events\ProviderEventType;
use OneSMTP\Security\RecipientNormalizer;
use OneSMTP\Security\SiteSecretHmac;

/**
 * Small, deterministic, synthetic provider-event fixtures for pure tests.
 */
final class ProviderEventFixtures
{
    private const SITE_SECRET = 'fixture-site-secret-without-production-data';

    /**
     * @return array<string,mixed>
     */
    public static function payload(string $eventType = 'delivery', string $suffix = '001'): array
    {
        return [
            'event_id' => 'fixture-event-' . $suffix,
            'event_type' => $eventType,
            'recipient' => 'Recipient@example.test',
            'provider_message_id' => 'fixture-message-' . $suffix,
            'reason' => 'synthetic-fixture',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function payloads(): array
    {
        return [
            self::payload('delivery', '001'),
            self::payload('bounce', '002'),
            self::payload('complaint', '003'),
            self::payload('deferral', '004'),
        ];
    }

    public static function event(string $eventType = 'delivery', string $suffix = '001'): ProviderEvent
    {
        $normalizer = new RecipientNormalizer();
        $canonicalRecipient = $normalizer->normalize('Recipient@example.test');
        if ($canonicalRecipient === null) {
            throw new \UnexpectedValueException('Synthetic recipient fixture is invalid.');
        }

        $fingerprint = (new SiteSecretHmac(self::SITE_SECRET))->digest($canonicalRecipient);

        return new ProviderEvent(
            ProviderEventType::fromProviderValue($eventType),
            'fixture-provider',
            'fixture-event-' . $suffix,
            new DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            $fingerprint,
            'fixture-message-' . $suffix,
            'synthetic-fixture'
        );
    }
}
