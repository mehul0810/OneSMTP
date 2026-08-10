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

    /** @dataProvider boundedTimestampProvider */
    public function test_invalid_or_sane_range_timestamps_fall_back_to_the_receipt_clock(mixed $timestamp): void
    {
        $receiptTime = new DateTimeImmutable('2026-08-10T00:00:00+00:00');
        $normalizer = new MailgunEventNormalizer(new SiteSecretHmac('fixture-site-secret'), clock: static fn (): DateTimeImmutable => $receiptTime);
        $event = $normalizer->normalize([
            'event-data' => [
                'id' => 'bounded-' . md5( (string) $timestamp ),
                'event' => 'delivered',
                'timestamp' => $timestamp,
            ],
        ]);

        self::assertNotNull($event);
        self::assertSame($receiptTime->getTimestamp(), $event->getOccurredAt()->getTimestamp());
    }

    /** @return array<string,array{0:mixed}> */
    public static function boundedTimestampProvider(): array
    {
        return [
            'short epoch' => [9999],
            'far past date' => ['1999-12-31T23:59:59Z'],
            'huge numeric input' => ['999999999999999999999999999999'],
            'invalid date' => ['not-a-date'],
        ];
    }

    public function test_site_context_separates_recipient_fingerprints_with_the_same_site_secret(): void
    {
        $payload = [
            'event-data' => [
                'id' => 'site-context-event',
                'event' => 'complained',
                'recipient' => 'Recipient@example.test',
            ],
        ];
        $siteOne = (new MailgunEventNormalizer(new SiteSecretHmac('fixture-site-secret'), recipientContext: 'recipient.site.1'))->normalize($payload);
        $siteTwo = (new MailgunEventNormalizer(new SiteSecretHmac('fixture-site-secret'), recipientContext: 'recipient.site.2'))->normalize($payload);

        self::assertNotNull($siteOne);
        self::assertNotNull($siteTwo);
        self::assertNotSame($siteOne->getRecipientFingerprint(), $siteTwo->getRecipientFingerprint());
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
            'permanent fail requires explicit severity' => ['permanent_fail', ProviderEventType::UNKNOWN],
            'unknown' => ['future-provider-state', ProviderEventType::UNKNOWN],
        ];
    }
}
