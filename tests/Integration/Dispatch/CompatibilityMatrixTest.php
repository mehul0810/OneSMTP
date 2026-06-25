<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Integration\Dispatch;

use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Pipeline\SendPipeline;
use OneSMTP\Providers\FailureCategory;
use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\SendResult;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\RateLimit\RateLimiter;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Settings\RateLimitSettingsRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompatibilityMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_actions'] = [];
        $GLOBALS['onesmtp_test_filters'] = [];
        $GLOBALS['onesmtp_test_fired_actions'] = [];
        $GLOBALS['onesmtp_test_mail'] = [];
        $GLOBALS['onesmtp_test_remote_posts'] = [];
        $GLOBALS['onesmtp_test_scheduled_actions'] = [];
        $GLOBALS['onesmtp_test_transients'] = [];
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_object_cache'] = [];
        $GLOBALS['onesmtp_test_action_scheduler_available'] = true;
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['onesmtp_test_object_cache'] = [];

        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $mailArgs
     */
    #[DataProvider('compatibilityFixtures')]
    public function test_common_email_sources_capture_route_log_and_preserve_source_attribution(array $mailArgs, bool $shouldSucceed): void
    {
        $this->seedProvider(65, 'compatibility_matrix');

        $result = $shouldSucceed
            ? new SendResult(true, 'sent', 'sent', 'provider-message-' . $mailArgs['onesmtp_source']['fixture'])
            : new SendResult(false, 'temporary_timeout', 'Synthetic retryable timeout.', null, FailureCategory::TIMEOUT);
        $adapter = new CompatibilityMatrixAdapter('compatibility_matrix', $result);
        $pipeline = $this->buildPipeline($adapter);

        $sendResult = $pipeline->handlePreWpMail(null, $mailArgs);

        self::assertSame($shouldSucceed, $sendResult);
        self::assertSame($mailArgs['onesmtp_source']['type'], $adapter->lastMessage['onesmtp_source']['type'] ?? null);
        self::assertSame($mailArgs['onesmtp_source']['fixture'], $adapter->lastMessage['onesmtp_source']['fixture'] ?? null);

        $messageInsert = $this->findInsert('onesmtp_messages');
        self::assertNotNull($messageInsert);
        self::assertSame($mailArgs['subject'], $messageInsert['data']['subject']);
        self::assertSame(hash('sha256', wp_json_encode($mailArgs['to'])), $messageInsert['data']['recipients_hash']);
        self::assertSame(hash('sha256', (string) $mailArgs['message']), $messageInsert['data']['body_hash']);

        $payload = json_decode((string) $messageInsert['data']['payload_json'], true);
        self::assertIsArray($payload);
        self::assertSame($mailArgs['onesmtp_source'], $payload['onesmtp_source'] ?? null);
        self::assertContains('X-OneSMTP-Synthetic-Source: ' . $mailArgs['onesmtp_source']['type'], $payload['headers'] ?? []);
        self::assertContains('X-OneSMTP-Message-ID: ' . $mailArgs['onesmtp_source']['fixture'], $payload['headers'] ?? []);

        $captureEvent = $this->findEventInsert('message_captured');
        self::assertNotNull($captureEvent);
        $captureContext = json_decode((string) $captureEvent['data']['context_json'], true);
        self::assertSame($mailArgs['subject'], $captureContext['subject'] ?? null);
        self::assertSame($mailArgs['onesmtp_source']['fixture'], $captureContext['message_uuid'] ?? null);

        $attemptInsert = $this->findInsert('onesmtp_attempts');
        self::assertNotNull($attemptInsert);
        self::assertSame(65, $attemptInsert['data']['provider_id']);
        self::assertSame(1, $attemptInsert['data']['attempt_no']);
        self::assertSame('initial', $attemptInsert['data']['trigger_type']);

        if ($shouldSucceed) {
            self::assertSame('sent', $attemptInsert['data']['result']);
            self::assertSame('provider-message-' . $mailArgs['onesmtp_source']['fixture'], $attemptInsert['data']['provider_message_id']);

            $sentUpdate = $this->findUpdate('onesmtp_messages', 'sent');
            self::assertNotNull($sentUpdate);
            self::assertSame(65, $sentUpdate['data']['selected_provider_id']);
            self::assertNull($this->findEventInsert('retry_scheduled'));
            self::assertSame([], $GLOBALS['onesmtp_test_scheduled_actions']);

            $sentEvent = $this->findEventInsert('message_sent');
            self::assertNotNull($sentEvent);
            self::assertSame(65, $sentEvent['data']['provider_id']);

            return;
        }

        self::assertSame('fail', $attemptInsert['data']['result']);
        self::assertSame('temporary_timeout', $attemptInsert['data']['error_code']);
        self::assertSame(FailureCategory::TIMEOUT, $attemptInsert['data']['failure_category']);

        $retryUpdate = $this->findUpdate('onesmtp_messages', 'retry_scheduled');
        self::assertNotNull($retryUpdate);
        self::assertSame(2, $retryUpdate['data']['current_attempt']);

        self::assertCount(1, $GLOBALS['onesmtp_test_scheduled_actions']);
        $scheduled = array_values($GLOBALS['onesmtp_test_scheduled_actions'])[0];
        self::assertSame(RetryScheduler::ACTION_HOOK, $scheduled['hook']);
        self::assertSame(1, $scheduled['args'][0] ?? null);
        self::assertSame(2, $scheduled['args'][1] ?? null);

        $retryEvent = $this->findEventInsert('retry_scheduled');
        self::assertNotNull($retryEvent);
        $retryContext = json_decode((string) $retryEvent['data']['context_json'], true);
        self::assertSame(2, $retryContext['attempt'] ?? null);
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:bool}>
     */
    public static function compatibilityFixtures(): array
    {
        return [
            'core notification' => [
                self::fixture(
                    'core_notification',
                    'core-notification-fixture',
                    'core-recipient@example.test',
                    'Synthetic core notification',
                    'Fixture body for a core account notification.'
                ),
                true,
            ],
            'form-like send' => [
                self::fixture(
                    'form_like',
                    'form-like-fixture',
                    'form-recipient@example.test',
                    'Synthetic form notification',
                    'Fixture body for a plugin-neutral form notification.',
                    ['form_id' => 'fixture-form']
                ),
                true,
            ],
            'commerce-like metadata' => [
                self::fixture(
                    'commerce_like',
                    'commerce-like-fixture',
                    'commerce-recipient@example.test',
                    'Synthetic receipt update',
                    'Fixture body for a receipt or order update.',
                    ['object_id' => 'fixture-order-1001', 'event' => 'receipt_update']
                ),
                true,
            ],
            'membership-like metadata' => [
                self::fixture(
                    'membership_like',
                    'membership-like-fixture',
                    'member-recipient@example.test',
                    'Synthetic access update',
                    'Fixture body for an access or membership update.',
                    ['object_id' => 'fixture-member-2001', 'event' => 'access_update']
                ),
                true,
            ],
            'background job retry' => [
                self::fixture(
                    'background_job',
                    'background-job-fixture',
                    'job-recipient@example.test',
                    'Synthetic queued task notification',
                    'Fixture body for a queued background task notification.',
                    ['job_id' => 'fixture-job-3001', 'event' => 'task_notification']
                ),
                false,
            ],
        ];
    }

    /**
     * @param array<string,string> $metadata
     * @return array<string,mixed>
     */
    private static function fixture(
        string $sourceType,
        string $fixtureId,
        string $recipient,
        string $subject,
        string $message,
        array $metadata = []
    ): array {
        return [
            'to' => [$recipient],
            'subject' => $subject,
            'message' => $message,
            'headers' => [
                'X-OneSMTP-Message-ID: ' . $fixtureId,
                'X-OneSMTP-Synthetic-Source: ' . $sourceType,
            ],
            'attachments' => [],
            'onesmtp_source' => [
                'type' => $sourceType,
                'fixture' => $fixtureId,
                'origin' => 'synthetic',
                'metadata' => $metadata,
            ],
        ];
    }

    private function buildPipeline(CompatibilityMatrixAdapter $adapter): SendPipeline
    {
        $dispatch = new DefaultDispatchPolicy();
        $messages = new MessageRepository();
        $attempts = new AttemptRepository();
        $providers = new ProviderRepository();
        $events = new EventRepository();
        $retryScheduler = new RetryScheduler($dispatch, $messages, $attempts, $providers, $events);
        $deliveryManager = new ProviderDeliveryManager(new ProviderAdapterRegistry([$adapter->getSlug() => $adapter]));
        $deliveryEngine = new DeliveryEngine($providers, $attempts, $dispatch, $deliveryManager, $events);
        $rateLimiter = new RateLimiter($attempts, new RateLimitSettingsRepository());

        return new SendPipeline($messages, $attempts, $providers, $events, $retryScheduler, $deliveryEngine, $rateLimiter);
    }

    private function seedProvider(int $id, string $adapterType): void
    {
        $provider = [
            'id' => $id,
            'adapter_type' => $adapterType,
            'is_active' => 1,
            'priority' => 1,
            'weight' => 1,
            'config_json' => '{}',
        ];

        $GLOBALS['wpdb']->activeProviders = [$provider];
        $GLOBALS['wpdb']->providerRowsById[$id] = $provider;
    }

    private function findInsert(string $tableSuffix): ?array
    {
        foreach ($GLOBALS['wpdb']->inserts as $insert) {
            if (str_ends_with($insert['table'], $tableSuffix)) {
                return $insert;
            }
        }

        return null;
    }

    private function findUpdate(string $tableSuffix, string $status): ?array
    {
        foreach ($GLOBALS['wpdb']->updates as $update) {
            if (
                str_ends_with($update['table'], $tableSuffix)
                && (($update['data']['status'] ?? '') === $status)
            ) {
                return $update;
            }
        }

        return null;
    }

    private function findEventInsert(string $eventType): ?array
    {
        foreach ($GLOBALS['wpdb']->inserts as $insert) {
            if (
                str_ends_with($insert['table'], 'onesmtp_events')
                && (($insert['data']['event_type'] ?? '') === $eventType)
            ) {
                return $insert;
            }
        }

        return null;
    }
}

final class CompatibilityMatrixAdapter implements ProviderAdapterInterface
{
    /**
     * @var array<string,mixed>
     */
    public array $lastMessage = [];

    public function __construct(private string $slug, private SendResult $result)
    {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $this->lastMessage = $message;

        return $this->result;
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->result;
    }
}
