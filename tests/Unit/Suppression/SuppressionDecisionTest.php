<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Suppression;

use OneSMTP\Events\ProviderEvent;
use OneSMTP\Events\ProviderEventType;
use OneSMTP\Suppression\SuppressionDecision;
use OneSMTP\Suppression\SuppressionDecisionInterface;
use OneSMTP\Tests\Support\ProviderEventFixtures;
use PHPUnit\Framework\TestCase;

final class SuppressionDecisionTest extends TestCase
{
    public function test_decision_contract_keeps_unavailable_state_explicit(): void
    {
        $policy = new class() implements SuppressionDecisionInterface {
            public function decide(ProviderEvent $event): SuppressionDecision
            {
                if ($event->getRecipientFingerprint() === null) {
                    return SuppressionDecision::UNAVAILABLE;
                }

                return $event->getType()->isSuppressionSignal()
                    ? SuppressionDecision::SUPPRESS
                    : SuppressionDecision::ALLOW;
            }
        };

        self::assertSame(SuppressionDecision::SUPPRESS, $policy->decide(ProviderEventFixtures::event('complaint', '003')));
        self::assertTrue($policy->decide(ProviderEventFixtures::event('hard_bounce', '002'))->shouldSuppress());
        self::assertSame(SuppressionDecision::ALLOW, $policy->decide(ProviderEventFixtures::event('soft_bounce', '003')));
        self::assertSame(SuppressionDecision::ALLOW, $policy->decide(ProviderEventFixtures::event('bounce', '006')));
        self::assertSame(SuppressionDecision::ALLOW, $policy->decide(ProviderEventFixtures::event('delivery', '001')));
        self::assertFalse(SuppressionDecision::UNAVAILABLE->shouldSuppress());
    }

    public function test_events_without_recipient_identity_do_not_default_to_suppression(): void
    {
        $event = new ProviderEvent(
            ProviderEventType::HARD_BOUNCE,
            'fixture-provider',
            'fixture-event-no-recipient',
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00')
        );

        $policy = new class() implements SuppressionDecisionInterface {
            public function decide(ProviderEvent $event): SuppressionDecision
            {
                return $event->getRecipientFingerprint() === null
                    ? SuppressionDecision::UNAVAILABLE
                    : SuppressionDecision::SUPPRESS;
            }
        };

        self::assertSame(SuppressionDecision::UNAVAILABLE, $policy->decide($event));
    }
}
