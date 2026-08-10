<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Events;

use OneSMTP\Events\ProviderEvent;
use OneSMTP\Events\ProviderEventNormalizerInterface;
use OneSMTP\Events\ProviderEventType;
use OneSMTP\Events\ProviderEventVerificationResult;
use OneSMTP\Events\ProviderEventVerifierInterface;
use OneSMTP\Security\RecipientNormalizer;
use OneSMTP\Security\SiteSecretHmac;
use OneSMTP\Tests\Support\ProviderEventFixtures;
use PHPUnit\Framework\TestCase;

final class ProviderEventContractsTest extends TestCase
{
    public function test_verifier_contract_has_an_explicit_fail_closed_result(): void
    {
        $verifier = new class() implements ProviderEventVerifierInterface {
            /**
             * @param array<string,string> $headers
             */
            public function verify(string $payload, array $headers): ProviderEventVerificationResult
            {
                return $payload !== '' && isset($headers['x-fixture-signature'])
                    ? ProviderEventVerificationResult::VERIFIED
                    : ProviderEventVerificationResult::REJECTED;
            }
        };

        self::assertTrue($verifier->verify('bounded-fixture', ['x-fixture-signature' => 'valid'])->isAccepted());
        self::assertFalse($verifier->verify('', [])->isAccepted());
        self::assertFalse(ProviderEventVerificationResult::UNAVAILABLE->isAccepted());
    }

    public function test_normalizer_contract_returns_only_the_safe_event_dto(): void
    {
        $normalizer = new class() implements ProviderEventNormalizerInterface {
            /**
             * @param array<string,mixed> $payload
            */
            public function normalize(array $payload): ?ProviderEvent
            {
                $eventType = $payload['event_type'] ?? '';
                $type = ProviderEventType::fromProviderValue(is_string($eventType) ? $eventType : '');
                if ($type === ProviderEventType::UNKNOWN) {
                    return null;
                }

                $recipientValue = $payload['recipient'] ?? '';
                $recipient = (new RecipientNormalizer())->normalize(is_string($recipientValue) ? $recipientValue : '');
                if ($recipient === null) {
                    return null;
                }

                $eventId = $payload['event_id'] ?? '';
                $providerMessageId = $payload['provider_message_id'] ?? '';
                $reason = $payload['reason'] ?? '';

                return new ProviderEvent(
                    $type,
                    'fixture-provider',
                    is_string($eventId) ? $eventId : '',
                    new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
                    (new SiteSecretHmac('fixture-site-secret-without-production-data'))->digest($recipient),
                    is_string($providerMessageId) ? $providerMessageId : '',
                    is_string($reason) ? $reason : ''
                );
            }
        };

        $event = $normalizer->normalize(ProviderEventFixtures::payload('hard_bounce', '002'));

        self::assertInstanceOf(ProviderEvent::class, $event);
        self::assertSame(ProviderEventType::HARD_BOUNCE, $event->getType());
        self::assertNull($normalizer->normalize(ProviderEventFixtures::payload('future-state', '005')));
    }

    public function test_synthetic_payload_fixtures_remain_bounded(): void
    {
        foreach (ProviderEventFixtures::payloads() as $payload) {
            $encoded = wp_json_encode($payload);
            self::assertIsString($encoded);
            self::assertLessThanOrEqual(4096, strlen($encoded));
        }
    }
}
