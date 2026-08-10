<?php

declare(strict_types=1);

namespace OneSMTP\Suppression;

use DateTimeImmutable;
use DateTimeZone;
use OneSMTP\Events\ProviderEvent;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\SuppressionRepository;
use OneSMTP\Security\RecipientNormalizer;
use OneSMTP\Security\SiteSecretHmac;

final class SuppressionService
{
    public function __construct(
        private FeatureGate $featureGate,
        private SuppressionSettingsRepository $settings,
        private SuppressionRepository $repository,
        private ?SiteSecretHmac $hmac,
        private ?RecipientNormalizer $normalizer = null,
        private string $recipientContext = 'recipient'
    ) {
        $this->normalizer = $normalizer ?? new RecipientNormalizer();
    }

    public function isOperational(): bool
    {
        return $this->featureGate->isEnabled(FeatureGate::BOUNCE_SUPPRESSION)
            && $this->settings->get()->isEnabled()
            && $this->hmac instanceof SiteSecretHmac;
    }

    public function derive(ProviderEvent $event, ?int $providerId): void
    {
        if ( ! $this->isOperational() || ! $event->getType()->isSuppressionSignal() ) {
            return;
        }

        $fingerprint = $event->getRecipientFingerprint();
        $domain = $event->getRecipientDomain();
        if ($fingerprint === null || $domain === null) {
            return;
        }

        $days = max(1, min(120, (int) apply_filters('onesmtp_log_retention_days', 30)));
        $firstSeen = $event->getOccurredAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $expiry = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
        $this->repository->upsert(
            $fingerprint,
            $domain,
            $event->getType()->value,
            $event->getProvider(),
            $providerId,
            $event->getProviderMessageId(),
            $firstSeen,
            $expiry
        );
    }

    /**
     * Check all canonical recipients. A single active match suppresses the
     * complete message; no recipient value leaves this boundary.
     */
    public function suppresses(array $payload): bool
    {
        if ( ! $this->isOperational() || ! $this->hmac instanceof SiteSecretHmac ) {
            return false;
        }

        foreach ( [ 'to', 'cc', 'bcc' ] as $field ) {
            foreach ($this->recipients($payload[ $field ] ?? []) as $recipient) {
                $normalized = $this->normalizer->normalize($recipient);
                if ($normalized === null) {
                    continue;
                }

                $fingerprint = $this->hmac->digest($normalized, $this->recipientContext);
                if ($this->repository->hasActive($fingerprint)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function fingerprintForLookup(string $recipient): ?string
    {
        if ( ! $this->isOperational() || ! $this->hmac instanceof SiteSecretHmac ) {
            return null;
        }

        $normalized = $this->normalizer->normalize($recipient);

        return $normalized === null ? null : $this->hmac->digest($normalized, $this->recipientContext);
    }

    /** @return array<int,string> */
    private function recipients(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;\r\n]+/', $value) ?: [];
        }
        if ( ! is_array($value) ) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $result[] = trim( (string) $item );
            }
        }

        return array_values(array_filter($result, static fn (string $item): bool => $item !== ''));
    }
}
