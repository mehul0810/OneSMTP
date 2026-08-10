<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Events;

use OneSMTP\Api\ProviderEventController;
use OneSMTP\Events\MailgunEventNormalizer;
use OneSMTP\Events\MailgunEventVerifier;
use OneSMTP\Events\ProviderEventIngestionService;
use OneSMTP\Events\ProviderEventType;
use OneSMTP\Events\ProviderEventStoreResult;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\ProviderEventRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\SecretVault;
use OneSMTP\Security\SiteSecretHmac;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class ProviderEventIngestionTest extends TestCase
{
    private const KEY = 'fixture-mailgun-signing-key';
    private const NOW = 1700000000;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_object_cache'] = [];
        $GLOBALS['onesmtp_test_rest_routes'] = [];
    }

    /**
     * @dataProvider eventTypeProvider
     */
    public function test_valid_signed_events_are_persisted_once(string $eventType, ProviderEventType $expectedType): void
    {
        $service = $this->service(true, true);
        $body = $this->signedBody($eventType, 'event-' . $eventType);

        self::assertTrue($service->ingest($body, 'application/json; charset=UTF-8', []));
        self::assertCount(1, $GLOBALS['wpdb']->providerEventRowsByHash);

        $args = $GLOBALS['wpdb']->lastPrepared['args'] ?? [];
        self::assertContains($expectedType->value, $args);
        self::assertNotContains('Recipient@example.test', $args);
        self::assertNotContains('diagnostic=private', $args);
        if ($expectedType->isSuppressionSignal()) {
            self::assertGreaterThanOrEqual(2, $this->countSha256Args($args));
        } else {
            self::assertSame(1, $this->countSha256Args($args));
        }
        self::assertSame([], $GLOBALS['wpdb']->updates);
    }

    public function test_replay_is_idempotent_and_accepted_without_a_second_write(): void
    {
        $service = $this->service(true, true);
        $body = $this->signedBody('delivered', 'replayed-event');

        self::assertTrue($service->ingest($body, 'application/json', []));
        self::assertTrue($service->ingest($body, 'application/json', []));
        self::assertCount(1, $GLOBALS['wpdb']->providerEventRowsByHash);
    }

    public function test_provider_and_message_references_are_correlated_when_provider_message_id_matches(): void
    {
        $GLOBALS['wpdb']->providerEventMessageIds['7|provider-message-reference-event'] = 21;
        $service = $this->service(true, true);

        self::assertTrue($service->ingest($this->signedBody('delivered', 'reference-event'), 'application/json', []));
        $args = $GLOBALS['wpdb']->lastPrepared['args'] ?? [];

        self::assertContains(7, $args);
        self::assertContains(21, $args);
    }

    public function test_invalid_transport_signature_and_body_are_rejected_generically(): void
    {
        $service = $this->service(true, true);
        $body = $this->signedBody('delivered', 'invalid-event');
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $payload['signature']['signature'] = str_repeat('0', 64);
        $tampered = (string) wp_json_encode($payload);

        self::assertFalse($service->ingest($tampered, 'application/json', []));
        self::assertFalse($service->ingest($body, 'text/plain', []));
        self::assertFalse($service->ingest(str_repeat('x', ProviderEventIngestionService::MAX_BODY_BYTES + 1), 'application/json', []));
        self::assertFalse($this->service(true, false)->ingest($body, 'application/json', []));
        self::assertCount(0, $GLOBALS['wpdb']->providerEventRowsByHash);
    }

    public function test_missing_disabled_or_gated_provider_fails_closed_without_an_existence_oracle(): void
    {
        $body = $this->signedBody('delivered', 'gate-event');

        self::assertFalse($this->service(false, true)->ingest($body, 'application/json', []));
        $missingProviderService = $this->service(true, true);
        $GLOBALS['wpdb']->activeProviders = [];
        self::assertFalse($missingProviderService->ingest($body, 'application/json', []));
        self::assertCount(0, $GLOBALS['wpdb']->providerEventRowsByHash);
    }

    public function test_missing_site_secret_cannot_ingest_or_create_recipient_fingerprints(): void
    {
        $controller = new ProviderEventController(null);
        $response = $controller->receive(new ProviderEventRequest($this->signedBody('complained', 'no-secret-event'), 'application/json'));

        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame('provider_event_rejected', $response->get_error_code());

        $this->expectException(\InvalidArgumentException::class);
        new SiteSecretHmac('');
    }

    public function test_repository_duplicate_result_is_safe_under_a_race(): void
    {
        $normalizer = new MailgunEventNormalizer(new SiteSecretHmac('fixture-site-secret'));
        $event = $normalizer->normalize(
            [
                'event-data' => [
                    'id' => 'race-event',
                    'event' => 'unknown-state',
                ],
            ]
        );
        self::assertNotNull($event);
        $hash = ProviderEventRepository::externalEventHash($event);
        $GLOBALS['wpdb']->providerEventRowsByHash[ $hash ] = 44;

        self::assertSame(
            ProviderEventStoreResult::DUPLICATE,
            (new ProviderEventRepository())->record($event, 7, null)
        );
    }

    public function test_rest_route_is_public_at_the_wp_login_layer_but_rejects_with_one_generic_error(): void
    {
        $service = $this->service(true, true);
        $controller = new ProviderEventController($service);
        $controller->registerRoutes();

        self::assertCount(1, $GLOBALS['onesmtp_test_rest_routes']);
        $route = $GLOBALS['onesmtp_test_rest_routes'][0];
        self::assertSame('/webhooks/mailgun', $route['route']);
        self::assertSame([ProviderEventController::class, 'allowPublic'], $route['args'][0]['permission_callback']);
        self::assertTrue(ProviderEventController::allowPublic());

        $response = $controller->receive(new ProviderEventRequest($this->signedBody('delivered', 'rest-event'), 'application/json'));
        self::assertSame(202, $response->status);
        $rejected = $controller->receive(new ProviderEventRequest('{}', 'text/plain'));
        self::assertInstanceOf(WP_Error::class, $rejected);
        self::assertSame('provider_event_rejected', $rejected->get_error_code());
        self::assertSame(['status' => 400], $rejected->get_error_data());
        self::assertSame('Request could not be accepted.', $rejected->get_error_message());
    }

    /** @return array<string,array{0:string,1:ProviderEventType}> */
    public static function eventTypeProvider(): array
    {
        return [
            'delivered' => ['delivered', ProviderEventType::DELIVERED],
            'hard bounce' => ['hard_bounce', ProviderEventType::HARD_BOUNCE],
            'soft bounce' => ['soft_bounce', ProviderEventType::SOFT_BOUNCE],
            'complaint' => ['complaint', ProviderEventType::COMPLAINT],
            'deferred' => ['deferred', ProviderEventType::DEFERRED],
            'unknown' => ['future-state', ProviderEventType::UNKNOWN],
        ];
    }

    private function service(bool $gateEnabled, bool $https): ProviderEventIngestionService
    {
        if ( ! isset($GLOBALS['wpdb']->activeProviders) || $GLOBALS['wpdb']->activeProviders === [] ) {
            $vault = new SecretVault();
            $provider = [
                'id' => 7,
                'slug' => 'mailgun-primary',
                'name' => 'Mailgun primary',
                'adapter_type' => 'mailgun',
                'priority' => 10,
                'weight' => 1,
                'is_active' => 1,
                'circuit_state' => 'closed',
                'circuit_until' => null,
                'config_json' => wp_json_encode([
                    'webhook_signing_key' => $vault->encrypt(self::KEY),
                ]),
            ];
            $GLOBALS['wpdb']->activeProviders = [$provider];
        }

        $gate = new FeatureGate([FeatureGate::PROVIDER_EVENTS => $gateEnabled], true);
        $clock = static fn (): int => self::NOW;

        return new ProviderEventIngestionService(
            new ProviderRepository(),
            new ProviderEventRepository(),
            $gate,
            new MailgunEventNormalizer(new SiteSecretHmac('fixture-site-secret'), clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable('@' . self::NOW)),
            static fn (string $key): MailgunEventVerifier => new MailgunEventVerifier($key, $clock),
            static fn (): bool => $https
        );
    }

    private function signedBody(string $eventType, string $eventId): string
    {
        $token = 'fixture-token';
        $payload = [
            'signature' => [
                'timestamp' => (string) self::NOW,
                'token' => $token,
                'signature' => hash_hmac('sha256', (string) self::NOW . $token, self::KEY),
            ],
            'event-data' => [
                'id' => $eventId,
                'event' => $eventType,
                'timestamp' => self::NOW,
                'recipient' => 'Recipient@example.test',
                'message' => ['headers' => ['message-id' => 'provider-message-' . $eventId]],
                'diagnostic' => 'diagnostic=private',
            ],
        ];

        return (string) wp_json_encode($payload);
    }

    /** @param array<int,mixed> $args */
    private function countSha256Args(array $args): int
    {
        return count(array_filter($args, static fn (mixed $value): bool => is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1));
    }
}

final class ProviderEventRequest extends WP_REST_Request
{
    public function __construct(private string $body, private string $contentType)
    {
        parent::__construct();
    }

    public function get_body(): string
    {
        return $this->body;
    }

    public function get_header(string $header): string
    {
        return strtolower($header) === 'content-type' ? $this->contentType : '';
    }

    /** @return array<string,string> */
    public function get_headers(): array
    {
        return ['content-type' => $this->contentType];
    }
}
