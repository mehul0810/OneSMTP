<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Suppression;

use DateTimeImmutable;
use DateTimeZone;
use OneSMTP\Core\RetentionPolicy;
use OneSMTP\Events\ProviderEvent;
use OneSMTP\Events\ProviderEventType;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\SuppressionRepository;
use OneSMTP\Security\SiteSecretHmac;
use OneSMTP\Suppression\SuppressionService;
use OneSMTP\Suppression\SuppressionSettings;
use OneSMTP\Suppression\SuppressionSettingsRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class SuppressionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_filters'] = [];
    }

    public function test_default_off_and_gate_off_never_suppress_or_write(): void
    {
        $service = $this->service(false, false);
        self::assertFalse($service->isOperational());
        $service->derive($this->event(ProviderEventType::HARD_BOUNCE), 7);
        self::assertSame([], $GLOBALS['wpdb']->suppressionRowsByFingerprint);
        self::assertFalse($service->suppresses(['to' => ['recipient@example.test']]));
    }

    public function test_only_hard_bounce_and_complaint_derive_and_whole_message_matches(): void
    {
        $service = $this->service(true, true);
        $event = $this->event(ProviderEventType::HARD_BOUNCE);
        $service->derive($event, 7);
        self::assertCount(1, $GLOBALS['wpdb']->suppressionRowsByFingerprint);
        self::assertTrue(
            $service->suppresses(
                [
                    'to' => [ 'safe@example.test' ],
                    'cc' => [ 'recipient@example.test' ],
                ]
            )
        );
        self::assertFalse($service->suppresses(['to' => ['safe@example.test']]));

        $service->derive($this->event(ProviderEventType::SOFT_BOUNCE), 7);
        $service->derive($this->event(ProviderEventType::DEFERRED), 7);
        $service->derive($this->event(ProviderEventType::UNKNOWN), 7);
        self::assertCount(1, $GLOBALS['wpdb']->suppressionRowsByFingerprint);
    }

    public function test_domain_and_fingerprint_are_bounded_and_address_is_not_persisted(): void
    {
        $service = $this->service(true, true);
        $service->derive($this->event(ProviderEventType::COMPLAINT), 9);
        $row = array_values($GLOBALS['wpdb']->suppressionRowsByFingerprint)[0];
        self::assertSame('example.test', $row['recipient_domain']);
        self::assertSame(64, strlen( (string) $row['recipient_fingerprint'] ));
        self::assertArrayNotHasKey('recipient', $row);
        self::assertStringNotContainsString('recipient@example.test', (string) wp_json_encode($row));
    }

    public function test_exact_lookup_and_expired_record_allows_delivery(): void
    {
        $service = $this->service(true, true);
        $service->derive($this->event(ProviderEventType::HARD_BOUNCE), 7);
        $fingerprint = $service->fingerprintForLookup('recipient@example.test');
        self::assertNotNull($fingerprint);
        self::assertTrue($service->suppresses(['to' => ['Recipient <recipient@example.test>']]));

        $GLOBALS['wpdb']->suppressionRowsByFingerprint[ (string) $fingerprint ]['expiry_at'] = '2000-01-01 00:00:00';
        self::assertFalse($service->suppresses(['to' => ['recipient@example.test']]));
    }

    public function test_lookup_remains_available_for_management_when_enforcement_is_disabled(): void
    {
        $settings = new SuppressionSettingsRepository();
        $settings->save(new SuppressionSettings(true));
        $repository = new SuppressionRepository();
        $service = new SuppressionService(
            new FeatureGate([FeatureGate::BOUNCE_SUPPRESSION => true], true),
            $settings,
            $repository,
            new SiteSecretHmac('fixture-site-secret'),
            recipientContext: 'recipient.site.1'
        );

        self::assertTrue($service->derive($this->event(ProviderEventType::COMPLAINT), 7));
        $settings->save(new SuppressionSettings(false));
        $fingerprint = $service->fingerprintForLookup('recipient@example.test');

        self::assertNotNull($fingerprint);
        self::assertTrue($repository->remove(strval($fingerprint)));
        self::assertFalse($repository->remove(strval($fingerprint)));
    }

    public function test_failed_derivation_is_pending_and_retry_is_idempotent(): void
    {
        $service = $this->service(true, true);
        $event = $this->event(ProviderEventType::HARD_BOUNCE, 'pending-event');
        $GLOBALS['wpdb']->failSuppressionUpsert = true;

        self::assertFalse($service->derive($event, 7));
        self::assertSame([], $GLOBALS['wpdb']->suppressionRowsByFingerprint);
        $eventHash = hash('sha256', $event->getProvider() . "\0" . $event->getEventId());
        self::assertSame(
            'pending',
            $GLOBALS['wpdb']->suppressionDerivationRowsByHash[ $eventHash ]['status'] ?? null
        );

        $GLOBALS['wpdb']->failSuppressionUpsert = false;
        self::assertTrue($service->derive($event, 7));
        self::assertTrue($service->derive($event, 7));
        self::assertSame('processed', $GLOBALS['wpdb']->suppressionDerivationRowsByHash[ $eventHash ]['status'] ?? null);
        $row = array_values($GLOBALS['wpdb']->suppressionRowsByFingerprint)[0] ?? [];
        self::assertSame(1, $row['occurrence_count'] ?? null);
    }

    public function test_derivation_uses_configured_retention_and_bounds_expiry(): void
    {
        $service = $this->service(true, true);

        RetentionPolicy::saveDays(90);
        self::assertTrue($service->derive($this->event(ProviderEventType::HARD_BOUNCE, 'retention-90'), 7));
        $rows = array_values($GLOBALS['wpdb']->suppressionRowsByFingerprint);
        $expiry90 = strtotime(strval($rows[0]['expiry_at'] ?? ''));
        self::assertGreaterThanOrEqual(time() + (89 * DAY_IN_SECONDS), $expiry90);
        self::assertLessThanOrEqual(time() + (91 * DAY_IN_SECONDS), $expiry90);

        RetentionPolicy::saveDays(120);
        self::assertTrue($service->derive($this->event(ProviderEventType::COMPLAINT, 'retention-120'), 7));
        $rows = array_values($GLOBALS['wpdb']->suppressionRowsByFingerprint);
        $expiry120 = strtotime(strval($rows[0]['expiry_at'] ?? ''));
        self::assertGreaterThanOrEqual(time() + (119 * DAY_IN_SECONDS), $expiry120);
        self::assertLessThanOrEqual(time() + (121 * DAY_IN_SECONDS), $expiry120);

        update_option(RetentionPolicy::OPTION, 999, false);
        self::assertSame(RetentionPolicy::MAX_DAYS, RetentionPolicy::getLogRetentionDays());
    }

    private function service(bool $gateEnabled, bool $settingEnabled): SuppressionService
    {
        $settings = new SuppressionSettingsRepository();
        $settings->save(new SuppressionSettings($settingEnabled));

        return new SuppressionService(
            new FeatureGate([FeatureGate::BOUNCE_SUPPRESSION => $gateEnabled], $gateEnabled),
            $settings,
            new SuppressionRepository(),
            new SiteSecretHmac('fixture-site-secret'),
            recipientContext: 'recipient.site.1'
        );
    }

    private function event(ProviderEventType $type, string $eventId = 'suppression-event'): ProviderEvent
    {
        return new ProviderEvent(
            $type,
            'mailgun',
            $eventId,
            new DateTimeImmutable('2026-08-10 00:00:00', new DateTimeZone('UTC')),
            (new SiteSecretHmac('fixture-site-secret'))->digest('recipient@example.test', 'recipient.site.1'),
            'provider-message',
            'example.test'
        );
    }
}
