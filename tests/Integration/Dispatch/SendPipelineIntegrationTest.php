<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Integration\Dispatch;

use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Pipeline\SendPipeline;
use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\SendResult;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class SendPipelineIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_actions'] = [];
        $GLOBALS['onesmtp_test_filters'] = [];
        $GLOBALS['onesmtp_test_fired_actions'] = [];
        $GLOBALS['onesmtp_test_scheduled_actions'] = [];
        $GLOBALS['onesmtp_test_transients'] = [];
        $GLOBALS['onesmtp_test_object_cache'] = [];
        $GLOBALS['onesmtp_test_action_scheduler_available'] = true;
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_handle_pre_wp_mail_captures_sends_and_marks_message_sent(): void
    {
        $this->seedProvider(10, 'test_success');

        $pipeline = $this->buildPipeline(new StaticAdapter('test_success', new SendResult(true, 'sent', 'sent', 'provider-123')));

        $result = $pipeline->handlePreWpMail(null, [
            'to' => ['qa@example.com'],
            'subject' => 'Pipeline success',
            'message' => 'Hello',
            'headers' => [],
        ]);

        self::assertTrue($result);

        $messageInsert = $this->findInsert('onesmtp_messages');
        self::assertNotNull($messageInsert);
        self::assertSame('Pipeline success', $messageInsert['data']['subject']);

        $attemptInsert = $this->findInsert('onesmtp_attempts');
        self::assertNotNull($attemptInsert);
        self::assertSame(10, $attemptInsert['data']['provider_id']);
        self::assertSame('sent', $attemptInsert['data']['result']);
        self::assertSame('initial', $attemptInsert['data']['trigger_type']);

        $sentUpdate = $this->findUpdate('onesmtp_messages', 'sent');
        self::assertNotNull($sentUpdate);
        self::assertSame(10, $sentUpdate['data']['selected_provider_id']);

        $sentEvent = $this->findEventInsert('message_sent');
        self::assertNotNull($sentEvent);
        self::assertSame(10, $sentEvent['data']['provider_id']);
    }

    public function test_handle_pre_wp_mail_persists_failed_attempt_and_schedules_retry(): void
    {
        $this->seedProvider(20, 'test_failure');

        $pipeline = $this->buildPipeline(new StaticAdapter('test_failure', new SendResult(false, 'provider_failed', 'failed')));

        $result = $pipeline->handlePreWpMail(null, [
            'to' => ['qa@example.com'],
            'subject' => 'Pipeline failure',
            'message' => 'Hello',
            'headers' => [],
        ]);

        self::assertFalse($result);

        $attemptInsert = $this->findInsert('onesmtp_attempts');
        self::assertNotNull($attemptInsert);
        self::assertSame(20, $attemptInsert['data']['provider_id']);
        self::assertSame('fail', $attemptInsert['data']['result']);
        self::assertSame('provider_failed', $attemptInsert['data']['error_code']);

        $retryUpdate = $this->findUpdate('onesmtp_messages', 'retry_scheduled');
        self::assertNotNull($retryUpdate);
        self::assertSame(2, $retryUpdate['data']['current_attempt']);

        self::assertCount(1, $GLOBALS['onesmtp_test_scheduled_actions']);
        $retryEvent = $this->findEventInsert('retry_scheduled');
        self::assertNotNull($retryEvent);
    }

    public function test_manual_resend_uses_forced_provider_and_records_lineage_attempt(): void
    {
        $this->seedProvider(30, 'test_resend');
        $GLOBALS['wpdb']->messageRowsById[501] = [
            'id' => 501,
            'message_uuid' => 'lineage-501',
            'payload_json' => wp_json_encode(
                [
                    'to' => ['qa@example.com'],
                    'subject' => 'Manual resend',
                    'message' => 'Hello',
                    'headers' => ['X-OneSMTP-Message-ID: lineage-501'],
                ]
            ),
            'status' => 'failed',
            'max_attempts' => 6,
        ];
        $GLOBALS['wpdb']->attemptHistoryByMessage[501] = [
            ['id' => 1, 'message_id' => 501, 'attempt_no' => 1, 'provider_id' => 30, 'result' => 'fail'],
        ];

        $pipeline = $this->buildPipeline(new StaticAdapter('test_resend', new SendResult(true, 'sent', 'resent', 'provider-resend-123')));

        self::assertTrue($pipeline->resendMessage(501, 30));

        $attemptInsert = $this->findInsert('onesmtp_attempts');
        self::assertNotNull($attemptInsert);
        self::assertSame(501, $attemptInsert['data']['message_id']);
        self::assertSame(2, $attemptInsert['data']['attempt_no']);
        self::assertSame(30, $attemptInsert['data']['provider_id']);
        self::assertSame('manual_resend', $attemptInsert['data']['trigger_type']);
        self::assertSame('sent', $attemptInsert['data']['result']);
        self::assertSame('provider-resend-123', $attemptInsert['data']['provider_message_id']);

        $sentUpdate = $this->findUpdate('onesmtp_messages', 'sent');
        self::assertNotNull($sentUpdate);
        self::assertSame(30, $sentUpdate['data']['selected_provider_id']);
    }

    private function buildPipeline(StaticAdapter $adapter): SendPipeline
    {
        $dispatch = new DefaultDispatchPolicy();
        $messages = new MessageRepository();
        $attempts = new AttemptRepository();
        $providers = new ProviderRepository();
        $events = new EventRepository();
        $retryScheduler = new RetryScheduler($dispatch, $messages, $attempts, $providers, $events);
        $deliveryManager = new ProviderDeliveryManager(new ProviderAdapterRegistry([$adapter->getSlug() => $adapter]));
        $deliveryEngine = new DeliveryEngine($providers, $attempts, $dispatch, $deliveryManager, $events);

        return new SendPipeline($messages, $attempts, $providers, $events, $retryScheduler, $deliveryEngine);
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

final class StaticAdapter implements ProviderAdapterInterface
{
    public function __construct(private string $slug, private SendResult $result)
    {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        return $this->result;
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->result;
    }
}
