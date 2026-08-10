<?php

declare(strict_types=1);

namespace OneSMTP\Events;

use JsonException;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\ProviderEventRepository;
use OneSMTP\Repository\ProviderRepository;

/**
 * Fail-closed, site-local Mailgun webhook ingestion boundary.
 */
final class ProviderEventIngestionService
{
    public const MAX_BODY_BYTES = 65536;

    public function __construct(
        private ProviderRepository $providers,
        private ProviderEventRepository $events,
        private FeatureGate $featureGate,
        private ProviderEventNormalizerInterface $normalizer,
        /** @var callable(string):ProviderEventVerifierInterface */
        private $verifierFactory,
        /** @var callable():bool|null */
        private $httpsCheck = null,
        /** @var callable(ProviderEvent,?int):void|null */
        private $acceptedEventHandler = null
    ) {
        $this->httpsCheck = $httpsCheck ?? static fn (): bool => function_exists('is_ssl') ? (bool) is_ssl() : true;
    }

    /**
     * Return true for an inserted or replayed event so the provider can stop
     * retrying. All rejected paths deliberately collapse to false.
     *
     * @param array<string,string> $headers
     */
    public function ingest(string $body, string $contentType, array $headers): bool
    {
        if ( ! ( $this->httpsCheck )() || strlen($body) > self::MAX_BODY_BYTES || ! $this->isJsonContentType($contentType) ) {
            return false;
        }

        if ( ! $this->featureGate->isEnabled(FeatureGate::PROVIDER_EVENTS) ) {
            return false;
        }

        $provider = $this->providers->findActiveByType(ProviderTypes::MAILGUN);
        if ( ! is_array($provider) ) {
            return false;
        }

        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $signingKey = isset($config['webhook_signing_key']) && is_string($config['webhook_signing_key'])
            ? trim($config['webhook_signing_key'])
            : '';
        if ($signingKey === '') {
            return false;
        }

        $verifier = ($this->verifierFactory)($signingKey);
        if ( ! $verifier instanceof ProviderEventVerifierInterface || ! $verifier->verify($body, $headers)->isAccepted() ) {
            return false;
        }

        $replayTokenHash = $verifier instanceof ReplayTokenHashProviderInterface
            ? $verifier->getReplayTokenHash()
            : null;
        if ($replayTokenHash === null) {
            return false;
        }

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if ( ! is_array($payload) ) {
            return false;
        }

        $event = $this->normalizer->normalize($payload);
        if ( ! $event instanceof ProviderEvent ) {
            return false;
        }

        $providerId = (int) ($provider['id'] ?? 0);
        $messageId = $this->events->findMessageId($providerId, $event->getProviderMessageId());

        $result = $this->events->record($event, $providerId > 0 ? $providerId : null, $messageId, $replayTokenHash);
        if ($result === ProviderEventStoreResult::INSERTED && is_callable($this->acceptedEventHandler)) {
            ($this->acceptedEventHandler)($event, $providerId > 0 ? $providerId : null);
        }

        return $result->isAccepted();
    }

    private function isJsonContentType(string $contentType): bool
    {
        $contentType = strtolower(trim($contentType));
        if (str_contains($contentType, ';')) {
            $contentType = trim( (string) strstr($contentType, ';', true) );
        }

        return $contentType === 'application/json';
    }
}
