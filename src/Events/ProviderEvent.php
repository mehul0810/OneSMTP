<?php

declare(strict_types=1);

namespace OneSMTP\Events;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable, provider-neutral event data with no raw provider payload fields.
 */
final class ProviderEvent
{
    private const MAX_PROVIDER_LENGTH = 64;
    private const MAX_EVENT_ID_LENGTH = 128;
    private const MAX_MESSAGE_ID_LENGTH = 128;

    private string $provider;
    private string $eventId;
    private ?string $recipientFingerprint;
    private ?string $providerMessageId;

    public function __construct(
        private ProviderEventType $type,
        string $provider,
        string $eventId,
        private DateTimeImmutable $occurredAt,
        ?string $recipientFingerprint = null,
        ?string $providerMessageId = null
    ) {
        $this->provider = self::requiredValue($provider, self::MAX_PROVIDER_LENGTH);
        $this->eventId = self::requiredValue($eventId, self::MAX_EVENT_ID_LENGTH);
        $this->recipientFingerprint = self::fingerprintValue($recipientFingerprint);
        $this->providerMessageId = self::optionalValue($providerMessageId, self::MAX_MESSAGE_ID_LENGTH);
    }

    public function getType(): ProviderEventType
    {
        return $this->type;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getRecipientFingerprint(): ?string
    {
        return $this->recipientFingerprint;
    }

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    /**
     * Return only bounded, normalized fields suitable for a future boundary.
     *
     * @return array{type:string,provider:string,event_id:string,occurred_at:string,recipient_fingerprint:?string,provider_message_id:?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'provider' => $this->provider,
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'recipient_fingerprint' => $this->recipientFingerprint,
            'provider_message_id' => $this->providerMessageId,
        ];
    }

    private static function requiredValue(string $value, int $maxLength): string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > $maxLength || self::hasControlCharacters($value)) {
            throw new InvalidArgumentException('Provider event field is invalid.');
        }

        return $value;
    }

    private static function optionalValue(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > $maxLength || self::hasControlCharacters($value)) {
            throw new InvalidArgumentException('Provider event field is invalid.');
        }

        return $value;
    }

    private static function fingerprintValue(?string $fingerprint): ?string
    {
        if ($fingerprint === null || $fingerprint === '') {
            return null;
        }

        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new InvalidArgumentException('Provider event recipient fingerprint is invalid.');
        }

        return $fingerprint;
    }

    private static function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
