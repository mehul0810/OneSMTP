<?php

declare(strict_types=1);

namespace OneSMTP\Events;

use DateTimeImmutable;
use DateTimeZone;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Security\RecipientNormalizer;
use OneSMTP\Security\SiteSecretHmac;

/**
 * Convert a bounded Mailgun payload into the provider-neutral event DTO.
 */
final class MailgunEventNormalizer implements ProviderEventNormalizerInterface
{
    private const MAX_EVENT_ID_LENGTH = 128;
    private const MAX_MESSAGE_ID_LENGTH = 128;
    private const MIN_OCCURRENCE_TIMESTAMP = 946684800;
    private const MAX_OCCURRENCE_TIMESTAMP = 4102444800;

    public function __construct(
        private SiteSecretHmac $recipientHmac,
        private ?RecipientNormalizer $recipientNormalizer = null,
        /** @var callable():DateTimeImmutable|null */
        private $clock = null,
        private string $recipientContext = 'recipient'
    ) {
        $this->recipientNormalizer = $recipientNormalizer ?? new RecipientNormalizer();
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function normalize(array $payload): ?ProviderEvent
    {
        $eventData = isset($payload['event-data']) && is_array($payload['event-data'])
            ? $payload['event-data']
            : (isset($payload['event_data']) && is_array($payload['event_data']) ? $payload['event_data'] : $payload);

        $eventId = $this->scalarString($eventData['id'] ?? $eventData['event_id'] ?? $payload['event_id'] ?? '');
        if ($eventId === '' || strlen($eventId) > self::MAX_EVENT_ID_LENGTH || $this->hasControlCharacters($eventId)) {
            return null;
        }

        $type = $this->normalizeType($eventData);
        $providerMessageId = $this->providerMessageId($eventData);
        $occurredAt = $this->occurredAt($eventData);
        $recipientFingerprint = null;

        if ($type->isSuppressionSignal()) {
            $recipient = $this->recipientNormalizer->normalize(
                $this->scalarString($eventData['recipient'] ?? $payload['recipient'] ?? '')
            );
            if ($recipient !== null) {
                $recipientFingerprint = $this->recipientHmac->digest($recipient, $this->recipientContext);
            }
        }

        try {
            return new ProviderEvent(
                $type,
                ProviderTypes::MAILGUN,
                $eventId,
                $occurredAt,
                $recipientFingerprint,
                $providerMessageId
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $eventData
     */
    private function normalizeType(array $eventData): ProviderEventType
    {
        $rawType = $this->scalarString($eventData['event'] ?? $eventData['event_type'] ?? '');
        $normalizedRawType = strtolower(str_replace('-', '_', $rawType));
        if ($normalizedRawType === 'temporary_fail') {
            $severity = $this->failureSeverity($eventData);

            return in_array($severity, ['temporary', 'transient', 'soft'], true)
                ? ProviderEventType::SOFT_BOUNCE
                : ProviderEventType::DEFERRED;
        }

        if ($normalizedRawType === 'permanent_fail') {
            return strtolower($this->failureSeverity($eventData)) === 'permanent'
                ? ProviderEventType::HARD_BOUNCE
                : ProviderEventType::UNKNOWN;
        }

        $type = ProviderEventType::fromProviderValue($rawType);
        if ($type !== ProviderEventType::UNKNOWN) {
            return $type;
        }

        if (strtolower($rawType) !== 'failed') {
            return ProviderEventType::UNKNOWN;
        }

        $severity = $this->failureSeverity($eventData);

        return match (strtolower($severity)) {
            'permanent' => ProviderEventType::HARD_BOUNCE,
            'temporary', 'transient' => ProviderEventType::SOFT_BOUNCE,
            default => ProviderEventType::UNKNOWN,
        };
    }

    /** @param array<string,mixed> $eventData */
    private function failureSeverity(array $eventData): string
    {
        $deliveryStatus = isset($eventData['delivery-status']) && is_array($eventData['delivery-status'])
            ? $eventData['delivery-status']
            : (isset($eventData['delivery_status']) && is_array($eventData['delivery_status']) ? $eventData['delivery_status'] : []);

        return strtolower($this->scalarString($deliveryStatus['severity'] ?? $eventData['severity'] ?? ''));
    }

    /**
     * @param array<string,mixed> $eventData
     */
    private function providerMessageId(array $eventData): ?string
    {
        $message = isset($eventData['message']) && is_array($eventData['message']) ? $eventData['message'] : [];
        $headers = isset($message['headers']) && is_array($message['headers']) ? $message['headers'] : [];
        $value = $eventData['provider_message_id']
            ?? $eventData['message-id']
            ?? $headers['message-id']
            ?? $headers['message_id']
            ?? '';
        $value = $this->scalarString($value);

        if ($value === '' || strlen($value) > self::MAX_MESSAGE_ID_LENGTH || $this->hasControlCharacters($value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $eventData
     */
    private function occurredAt(array $eventData): DateTimeImmutable
    {
        $timestamp = $eventData['timestamp'] ?? $eventData['occurred_at'] ?? null;
        $timestampValue = is_scalar($timestamp) ? trim( (string) $timestamp ) : '';
        if (strlen($timestampValue) <= 20 && preg_match('/\A\d{1,12}(?:\.\d{1,6})?\z/D', $timestampValue) === 1) {
            $seconds = (int) $timestampValue;
            if ($seconds >= self::MIN_OCCURRENCE_TIMESTAMP && $seconds <= self::MAX_OCCURRENCE_TIMESTAMP) {
                return (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));
            }
        }

        if (is_string($timestamp) && strlen($timestamp) <= 64 && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', trim($timestamp)) === 1) {
            try {
                $date = (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'));
                if ($date->getTimestamp() >= self::MIN_OCCURRENCE_TIMESTAMP && $date->getTimestamp() <= self::MAX_OCCURRENCE_TIMESTAMP) {
                    return $date;
                }
            } catch (\Exception $exception) {
                // Fall back to the bounded receipt clock for malformed provider dates.
                unset($exception);
            }
        }

        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'));
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim( (string) $value ) : '';
    }

    private function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
