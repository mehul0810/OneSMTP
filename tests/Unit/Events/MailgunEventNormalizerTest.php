<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Events;

use DateTimeImmutable;
use OneSMTP\Events\MailgunEventNormalizer;
use OneSMTP\Events\ProviderEventType;
use OneSMTP\Security\SiteSecretHmac;
use PHPUnit\Framework\TestCase;

final class MailgunEventNormalizerTest extends TestCase
{
    /**
     * @dataProvider eventTypeProvider
     */
    public function test_supported_mailgun_events_normalize_to_bounded_types(string $event, ProviderEventType $expected): void
    {
        $normalizer = new MailgunEventNormalizer(
            new SiteSecretHmac('fixture-site-secret'),
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-10T00:00:00+00:00')
        );
        $payload = [
            'event-data' => [
                'id' => 'event-' . $event,
                'event' => $event,
                'timestamp' => 1786320000,
                'recipient' => 'Recipient@example.test',
                'message' => ['headers' => ['message-id' => 'provider-message-' . $event]],
            ],
            'raw_payload' => 'never-retained',
        ];

        $normalized = $normalizer->normalize($payload);

        self::assertNotNull($normalized);
        self::assertSame($expected, $normalized->getType());
        self::assertSame('mailgun', $normalized->getProvider());
        self::assertSame('provider-message-' . $event, $normalized->getProviderMessageId());
        self::assertSame(
            $expected->isSuppressionSignal() ? 64 : 0,
            $normalized->getRecipientFingerprint() === null ? 0 : strlen($normalized->getRecipientFingerprint())
        );
        self::assertStringNotContainsString('Recipient@example.test', (string) wp_json_encode($normalized->toArray()));
        self::assertStringNotContainsString('never-retained', (string) wp_json_encode($normalized->toArray()));
    }

    public function test_failed_mailgun_events_use_severity_only_for_bounce_categories(): void
    {
        $normalizer = new MailgunEventNormalizer(new SiteSecretHmac('fixture-site-secret'));

        $permanent = $normalizer->normalize([
            'event-data' => [
                'id' => 'permanent-failure',
                'event' => 'failed',
                'delivery-status' => ['severity' => 'permanent'],
            ],
        ]);
        $temporary = $normalizer->normalize([
            'event-data' => [
                'id' => 'temporary-failure',
                'event' => 'failed',
                'delivery-status' => ['severity' => 'temporary'],
            ],
        ]);

        self::assertNotNull($permanent);
        self::assertNotNull($temporary);
        self::assertSame(ProviderEventType::HARD_BOUNCE, $permanent->getType());
        self::assertSame(ProviderEventType::SOFT_BOUNCE, $temporary->getType());
    }

    public function test_event_without_an_external_id_is_rejected(): void
    {
        $normalizer = new MailgunEventNormalizer(new SiteSecretHmac('fixture-site-secret'));

        self::assertNull($normalizer->normalize(['event-data' => ['event' => 'delivered']]));
    }

    /** @return array<string,array{0:string,1:ProviderEventType}> */
    public static function eventTypeProvider(): array
    {
        return [
            'delivered' => ['delivered', ProviderEventType::DELIVERED],
            'accepted is unknown' => ['accepted', ProviderEventType::UNKNOWN],
            'hard bounce' => ['hard_bounce', ProviderEventType::HARD_BOUNCE],
            'soft bounce' => ['soft_bounce', ProviderEventType::SOFT_BOUNCE],
            'complaint' => ['complaint', ProviderEventType::COMPLAINT],
            'complained' => ['complained', ProviderEventType::COMPLAINT],
            'deferred' => ['deferred', ProviderEventType::DEFERRED],
            'temporary fail defaults deferred' => ['temporary_fail', ProviderEventType::DEFERRED],
            'permanent fail is hard bounce' => ['permanent_fail', ProviderEventType::HARD_BOUNCE],
            'unknown' => ['future-provider-state', ProviderEventType::UNKNOWN],
        ];
    }
}
