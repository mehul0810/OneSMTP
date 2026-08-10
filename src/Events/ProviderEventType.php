<?php

declare(strict_types=1);

namespace OneSMTP\Events;

/**
 * Provider-neutral categories for provider-side delivery events.
 */
enum ProviderEventType: string
{
    case DELIVERED = 'delivered';
    case HARD_BOUNCE = 'hard_bounce';
    case SOFT_BOUNCE = 'soft_bounce';
    case COMPLAINT = 'complaint';
    case DEFERRED = 'deferred';
    case UNKNOWN = 'unknown';

    public static function fromProviderValue(?string $value): self
    {
        $normalized = strtolower(trim($value ?? ''));
        $normalized = preg_replace('/[\s-]+/', '_', $normalized) ?? '';

        return match ($normalized) {
            'delivered', 'delivery' => self::DELIVERED,
            'hard_bounce', 'permanent_bounce', 'permanent_failure', 'permanent_fail', 'bounced_hard' => self::HARD_BOUNCE,
            'soft_bounce', 'temporary_bounce', 'bounced_soft' => self::SOFT_BOUNCE,
            'complaint', 'complained', 'feedback', 'spam_complaint' => self::COMPLAINT,
            'defer', 'deferred', 'deferral', 'temporary_failure', 'temporary_fail' => self::DEFERRED,
            default => self::UNKNOWN,
        };
    }

    public function isSuppressionSignal(): bool
    {
        return in_array($this, [self::HARD_BOUNCE, self::COMPLAINT], true);
    }
}
