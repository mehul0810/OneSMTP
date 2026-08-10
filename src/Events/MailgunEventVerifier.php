<?php

declare(strict_types=1);

namespace OneSMTP\Events;

use JsonException;

/**
 * Verify Mailgun's signed JSON webhook envelope without retaining it.
 */
final class MailgunEventVerifier implements ProviderEventVerifierInterface, ReplayTokenHashProviderInterface
{
    public const MAX_TIMESTAMP_SKEW = 300;

    private const MAX_TOKEN_LENGTH = 256;
    private const MAX_SIGNATURE_LENGTH = 128;

    private ?string $replayTokenHash = null;

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
        $this->replayTokenHash = null;
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
        $providedSignatures = $signature['signatures'];

        if ( ! ctype_digit($timestamp) || $token === '' || strlen($timestamp) > 12 || strlen($token) > self::MAX_TOKEN_LENGTH || $providedSignatures === [] ) {
            return ProviderEventVerificationResult::REJECTED;
        }

        foreach ($providedSignatures as $providedSignature) {
            if (strlen($providedSignature) > self::MAX_SIGNATURE_LENGTH) {
                return ProviderEventVerificationResult::REJECTED;
            }
        }

        $timestampValue = (int) $timestamp;
        if ( abs( (int) ( $this->clock )() - $timestampValue ) > self::MAX_TIMESTAMP_SKEW ) {
            return ProviderEventVerificationResult::REJECTED;
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $signingKey);
        $verified = false;
        foreach ($providedSignatures as $providedSignature) {
            $verified = hash_equals($expected, $providedSignature) || $verified;
        }

        if ( ! $verified) {
            return ProviderEventVerificationResult::REJECTED;
        }

        $this->replayTokenHash = hash('sha256', $token);

        return ProviderEventVerificationResult::VERIFIED;
    }

    public function getReplayTokenHash(): ?string
    {
        return $this->replayTokenHash;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array{timestamp:string,token:string,signatures:array<int,string>}
     */
    private function signatureFields(array $payload, array $headers): array
    {
        $signature = isset($payload['signature']) && is_array($payload['signature'])
            ? $payload['signature']
            : [];

        $signatures = [];
        foreach (['signature', 'parent-signature', 'parent_signature'] as $key) {
            $value = $this->scalarString($signature[ $key ] ?? $payload[ $key ] ?? '');
            if ($value !== '') {
                $signatures[] = $value;
            }
        }

        $headerSignature = $this->scalarString($headers['x-mailgun-signature'] ?? '');
        if ($headerSignature !== '') {
            $signatures[] = $headerSignature;
        }

        return [
            'timestamp' => $this->scalarString($signature['timestamp'] ?? $payload['timestamp'] ?? $headers['x-mailgun-timestamp'] ?? ''),
            'token' => $this->scalarString($signature['token'] ?? $payload['token'] ?? $headers['x-mailgun-token'] ?? ''),
            'signatures' => array_values(array_unique($signatures)),
        ];
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim( (string) $value ) : '';
    }
}
