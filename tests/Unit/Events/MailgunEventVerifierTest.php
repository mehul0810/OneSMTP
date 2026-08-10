<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Events;

use OneSMTP\Events\MailgunEventVerifier;
use OneSMTP\Events\ProviderEventVerificationResult;
use PHPUnit\Framework\TestCase;

final class MailgunEventVerifierTest extends TestCase
{
    private const KEY = 'fixture-mailgun-signing-key';
    private const NOW = 1700000000;

    public function test_valid_signature_is_accepted_within_the_five_minute_window(): void
    {
        $verifier = new MailgunEventVerifier(self::KEY, static fn (): int => self::NOW);

        self::assertSame(
            ProviderEventVerificationResult::VERIFIED,
            $verifier->verify($this->signedPayload(self::NOW), ['content-type' => 'application/json'])
        );
    }

    public function test_signature_is_rejected_when_tampered_or_expired(): void
    {
        $verifier = new MailgunEventVerifier(self::KEY, static fn (): int => self::NOW);
        $payload = json_decode($this->signedPayload(self::NOW), true, 32, JSON_THROW_ON_ERROR);
        $payload['signature']['signature'] = str_repeat('0', 64);

        self::assertSame(
            ProviderEventVerificationResult::REJECTED,
			$verifier->verify( (string) wp_json_encode($payload), [] )
        );
        self::assertSame(
            ProviderEventVerificationResult::REJECTED,
            $verifier->verify($this->signedPayload(self::NOW - 301), [])
        );
    }

    public function test_missing_key_and_malformed_json_fail_closed(): void
    {
        self::assertSame(
            ProviderEventVerificationResult::UNAVAILABLE,
            (new MailgunEventVerifier('', static fn (): int => self::NOW))->verify('{}', [])
        );
        self::assertSame(
            ProviderEventVerificationResult::REJECTED,
            (new MailgunEventVerifier(self::KEY, static fn (): int => self::NOW))->verify('{', [])
        );
    }

    private function signedPayload(int $timestamp): string
    {
        $token = 'fixture-token';
        $payload = [
            'signature' => [
                'timestamp' => (string) $timestamp,
                'token' => $token,
                'signature' => hash_hmac('sha256', (string) $timestamp . $token, self::KEY),
            ],
            'event-data' => [
                'id' => 'fixture-event-001',
                'event' => 'delivered',
            ],
        ];

        return (string) wp_json_encode($payload);
    }
}
