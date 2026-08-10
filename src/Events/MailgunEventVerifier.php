<?php

declare(strict_types=1);

namespace OneSMTP\Events;

use JsonException;

/**
 * Verify Mailgun's signed JSON webhook envelope without retaining it.
 */
final class MailgunEventVerifier implements ProviderEventVerifierInterface
{
    public const MAX_TIMESTAMP_SKEW = 300;

    private const MAX_TOKEN_LENGTH = 256;
    private const MAX_SIGNATURE_LENGTH = 128;

    /** @var callable():int */
    private $clock;

    /**
     * @param callable():int|null $clock
     */
    public function __construct(private string $signingKey, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param array<string,string> $headers
     */
    public function verify(string $payload, array $headers): ProviderEventVerificationResult
    {
        $signingKey = trim($this->signingKey);
        if ($signingKey === '' || strlen($signingKey) > 512) {
            return ProviderEventVerificationResult::UNAVAILABLE;
        }

        try {
            $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ProviderEventVerificationResult::REJECTED;
        }

        if ( ! is_array($decoded) ) {
            return ProviderEventVerificationResult::REJECTED;
        }

        $signature = $this->signatureFields($decoded, $headers);
        $timestamp = $signature['timestamp'];
        $token = $signature['token'];
        $providedSignature = $signature['signature'];

        if ( ! ctype_digit($timestamp) || strlen($timestamp) > 12 || strlen($token) > self::MAX_TOKEN_LENGTH || strlen($providedSignature) > self::MAX_SIGNATURE_LENGTH ) {
            return ProviderEventVerificationResult::REJECTED;
        }

        $timestampValue = (int) $timestamp;
        if ( abs( (int) ( $this->clock )() - $timestampValue ) > self::MAX_TIMESTAMP_SKEW ) {
            return ProviderEventVerificationResult::REJECTED;
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $signingKey);

        return hash_equals($expected, $providedSignature)
            ? ProviderEventVerificationResult::VERIFIED
            : ProviderEventVerificationResult::REJECTED;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array{timestamp:string,token:string,signature:string}
     */
    private function signatureFields(array $payload, array $headers): array
    {
        $signature = isset($payload['signature']) && is_array($payload['signature'])
            ? $payload['signature']
            : [];

        return [
            'timestamp' => $this->scalarString($signature['timestamp'] ?? $headers['x-mailgun-timestamp'] ?? ''),
            'token' => $this->scalarString($signature['token'] ?? $headers['x-mailgun-token'] ?? ''),
            'signature' => $this->scalarString($signature['signature'] ?? $headers['x-mailgun-signature'] ?? ''),
        ];
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim( (string) $value ) : '';
    }
}
