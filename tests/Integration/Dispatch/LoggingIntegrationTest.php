<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Integration\Dispatch;

use OneSMTP\Dispatch\DispatchPolicyInterface;
use OneSMTP\Queue\RetryScheduler;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class LoggingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_fired_actions'] = [];
        $GLOBALS['onesmtp_test_scheduled_actions'] = [];
        $GLOBALS['onesmtp_test_transients'] = [];
        $GLOBALS['onesmtp_test_action_scheduler_available'] = true;

        $wpdb = new FakeWpdb();
        $wpdb->messageRowsById[321] = [
            'id' => 321,
            'status' => 'retry_scheduled',
            'message_uuid' => 'msg-321',
            'payload_json' => json_encode([
                'to' => ['qa@example.com'],
                'subject' => 'retry test',
                'message' => 'hello',
            ]),
        ];
        $wpdb->activeProviders = [
            ['id' => 100],
            ['id' => 200],
            ['id' => 300],
        ];
        $wpdb->attemptHistoryByMessage[321] = [
            ['provider_id' => 100, 'result' => 'fail'],
            ['provider_id' => 100, 'result' => 'fail'],
        ];

        $GLOBALS['wpdb'] = $wpdb;
    }

    public function test_each_attempt_logs_provider_and_outcome_with_lineage_uuid(): void
    {
        $dispatch = $this->createMock(DispatchPolicyInterface::class);
        $dispatch->expects(self::once())
            ->method('chooseNextProvider')
            ->with(
                321,
                3,
                self::callback(static function (array $context): bool {
                    return ($context['last_provider_id'] ?? 0) === 100
                        && ($context['consecutive_failures_for_last_provider'] ?? 0) === 2
                        && is_array($context['providers'] ?? null)
                        && count($context['providers']) === 3;
                })
            )
            ->willReturn(200);

        $scheduler = new RetryScheduler(
            $dispatch,
            new MessageRepository(),
            new AttemptRepository(),
            new ProviderRepository(),
            new EventRepository()
        );

        $scheduler->processRetry(321, 3, 'msg-321');

        self::assertNotEmpty($GLOBALS['onesmtp_test_fired_actions']);
        $action = $GLOBALS['onesmtp_test_fired_actions'][0];
        self::assertSame('onesmtp_retry_attempt', $action['hook']);
        self::assertSame(321, $action['args'][0]);
        self::assertSame(3, $action['args'][1]);
        self::assertSame(200, $action['args'][2]);
        self::assertSame('msg-321', $action['args'][4]);

        $event = $this->findEventInsert('retry_dispatched');
        self::assertNotNull($event);
        self::assertSame(321, $event['data']['message_id']);
        self::assertSame(200, $event['data']['provider_id']);

        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame(3, $context['attempt'] ?? null);
    }

    public function test_terminal_state_written_after_retry_exhaustion_boundary(): void
    {
        $dispatch = $this->createMock(DispatchPolicyInterface::class);
        $scheduler = new RetryScheduler(
            $dispatch,
            new MessageRepository(),
            new AttemptRepository(),
            new ProviderRepository(),
            new EventRepository()
        );

        $runAt = $scheduler->scheduleRetry(321, 7, 'msg-321');

        self::assertNull($runAt);
        self::assertNotEmpty($GLOBALS['wpdb']->updates);
        self::assertSame('failed', $GLOBALS['wpdb']->updates[0]['data']['status']);
        self::assertSame(6, $GLOBALS['wpdb']->updates[0]['data']['current_attempt']);

        $event = $this->findEventInsert('terminal_failure');
        self::assertNotNull($event);
        $context = json_decode((string) $event['data']['context_json'], true);

        self::assertSame('max_retries_boundary', $context['reason'] ?? null);
        self::assertSame(7, $context['attempt'] ?? null);
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
