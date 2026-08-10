<?php

declare(strict_types=1);

namespace OneSMTP\Events;

/**
 * Provider-neutral categories for provider-side delivery events.
 */
enum ProviderEventType: string
{
    case DELIVERY = 'delivery';
    case BOUNCE = 'bounce';
    case COMPLAINT = 'complaint';
    case DEFERRAL = 'deferral';
    case UNKNOWN = 'unknown';

    public static function fromProviderValue(?string $value): self
    {
        $normalized = strtolower(trim($value ?? ''));
        $normalized = preg_replace('/[\s-]+/', '_', $normalized) ?? '';

        return match ($normalized) {
            'delivery', 'delivered', 'accepted', 'sent' => self::DELIVERY,
            'bounce', 'bounced' => self::BOUNCE,
            'complaint', 'complained', 'feedback', 'spam_complaint' => self::COMPLAINT,
            'defer', 'deferred', 'deferral', 'temporary_failure' => self::DEFERRAL,
            default => self::UNKNOWN,
        };
    }

    public function isSuppressionSignal(): bool
    {
        return in_array($this, [self::BOUNCE, self::COMPLAINT], true);
    }
}
