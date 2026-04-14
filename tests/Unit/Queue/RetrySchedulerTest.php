<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Queue;

use OneSMTP\Dispatch\DispatchPolicyInterface;
use OneSMTP\Queue\RetryScheduler;
use PHPUnit\Framework\TestCase;

final class RetrySchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['onesmtp_test_actions'] = [];
        $GLOBALS['onesmtp_test_scheduled_actions'] = [];
    }

    public function test_register_hooks_adds_retry_action_handler(): void
    {
        $policy = $this->createMock(DispatchPolicyInterface::class);
        $scheduler = new RetryScheduler($policy);

        $scheduler->registerHooks();

        self::assertNotEmpty($GLOBALS['onesmtp_test_actions']);
        self::assertSame(RetryScheduler::ACTION_HOOK, $GLOBALS['onesmtp_test_actions'][0]['hook']);
    }

    public function test_schedule_retry_uses_exponential_backoff_and_caps_at_one_hour(): void
    {
        $policy = $this->createMock(DispatchPolicyInterface::class);
        $scheduler = new RetryScheduler($policy);

        $before = time();

        $scheduler->scheduleRetry(101, 1);
        $scheduler->scheduleRetry(101, 2);
        $scheduler->scheduleRetry(101, 8);

        $first = $this->findScheduled(RetryScheduler::ACTION_HOOK, ['message_id' => 101, 'attempt' => 1], 'onesmtp');
        $second = $this->findScheduled(RetryScheduler::ACTION_HOOK, ['message_id' => 101, 'attempt' => 2], 'onesmtp');
        $eighth = $this->findScheduled(RetryScheduler::ACTION_HOOK, ['message_id' => 101, 'attempt' => 8], 'onesmtp');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotNull($eighth);

        self::assertSame(60, $first['timestamp'] - $before);
        self::assertSame(120, $second['timestamp'] - $before);
        self::assertSame(3600, $eighth['timestamp'] - $before);
    }

    public function test_schedule_retry_skips_duplicate_action_for_same_message_and_attempt(): void
    {
        $policy = $this->createMock(DispatchPolicyInterface::class);
        $scheduler = new RetryScheduler($policy);

        $scheduler->scheduleRetry(999, 3);
        $countAfterFirst = count($GLOBALS['onesmtp_test_scheduled_actions']);

        $scheduler->scheduleRetry(999, 3);

        self::assertSame($countAfterFirst, count($GLOBALS['onesmtp_test_scheduled_actions']));
    }

    public function test_process_retry_ignores_invalid_message_id(): void
    {
        $policy = $this->createMock(DispatchPolicyInterface::class);
        $policy->expects(self::never())->method('chooseNextProvider');

        $scheduler = new RetryScheduler($policy);
        $scheduler->processRetry(['message_id' => 0, 'attempt' => 2]);

        self::assertTrue(true);
    }

    public function test_process_retry_calls_dispatch_policy_with_payload_values(): void
    {
        $policy = $this->createMock(DispatchPolicyInterface::class);
        $policy->expects(self::once())
            ->method('chooseNextProvider')
            ->with(55, 4, []);

        $scheduler = new RetryScheduler($policy);
        $scheduler->processRetry(['message_id' => 55, 'attempt' => 4]);
    }

    private function findScheduled(string $hook, array $args, string $group): ?array
    {
        $index = $hook . '|' . $group . '|' . md5((string) wp_json_encode($args));

        return $GLOBALS['onesmtp_test_scheduled_actions'][$index] ?? null;
    }
}
