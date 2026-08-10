<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Integration\Dispatch;

use OneSMTP\Alerts\FailureAlertDispatcher;
use OneSMTP\Delivery\DeliveryEngine;
use OneSMTP\Dispatch\DefaultDispatchPolicy;
use OneSMTP\Pipeline\SendPipeline;
use OneSMTP\Product\FeatureGate;
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
use PHPUnit\Framework\TestCase;

final class SendPipelineIntegrationTest extends TestCase
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
        self::assertIsInt($attemptInsert['data']['latency_ms']);
        self::assertGreaterThanOrEqual(0, $attemptInsert['data']['latency_ms']);

        $sentUpdate = $this->findUpdate('onesmtp_messages', 'sent');
        self::assertNotNull($sentUpdate);
        self::assertSame(10, $sentUpdate['data']['selected_provider_id']);

        $sentEvent = $this->findEventInsert('message_sent');
        self::assertNotNull($sentEvent);
        self::assertSame(10, $sentEvent['data']['provider_id']);
    }

    public function test_retryable_failure_immediately_switches_to_secondary_provider_and_logs_failover(): void
    {
        $this->seedProviders([
            ['id' => 10, 'adapter_type' => 'sequence', 'priority' => 1],
            ['id' => 20, 'adapter_type' => 'sequence', 'priority' => 2],
        ]);
        $adapter = new SequenceAdapter('sequence', [
            new SendResult(false, 'provider_timeout', 'Primary timed out.', null, FailureCategory::TIMEOUT),
            new SendResult(true, 'sent', 'Secondary accepted the message.', 'secondary-message-id'),
        ]);

        $pipeline = $this->buildPipeline($adapter);

        $result = $pipeline->handlePreWpMail(null, [
            'to' => ['qa@example.com'],
            'subject' => 'Immediate failover',
            'message' => 'Hello',
            'headers' => [],
        ]);

        self::assertTrue($result);
        $attempts = array_values(array_filter(
            $GLOBALS['wpdb']->inserts,
            static fn (array $insert): bool => str_ends_with($insert['table'], 'onesmtp_attempts')
        ));
        self::assertCount(2, $attempts);
        self::assertSame(20, $attempts[0]['data']['provider_id']);
        self::assertSame('fail', $attempts[0]['data']['result']);
        self::assertSame(10, $attempts[1]['data']['provider_id']);
        self::assertSame('failover', $attempts[1]['data']['trigger_type']);
        self::assertSame('sent', $attempts[1]['data']['result']);
        self::assertSame([], $GLOBALS['onesmtp_test_scheduled_actions']);

        $failover = $this->findEventInsert('provider_failover');
        self::assertNotNull($failover);
        self::assertSame(10, $failover['data']['provider_id']);
        $context = json_decode((string) $failover['data']['context_json'], true);
        self::assertSame(20, $context['from_provider_id'] ?? null);
        self::assertSame(10, $context['to_provider_id'] ?? null);
    }

    public function test_provider_scoped_failure_on_secondary_continues_to_third_provider(): void
    {
        $this->seedProviders([
            ['id' => 10, 'adapter_type' => 'sequence', 'priority' => 1],
            ['id' => 20, 'adapter_type' => 'sequence', 'priority' => 2],
            ['id' => 30, 'adapter_type' => 'sequence', 'priority' => 3],
        ]);
        $adapter = new SequenceAdapter('sequence', [
            new SendResult(false, 'provider_timeout', 'Primary timed out.', null, FailureCategory::TIMEOUT),
            new SendResult(false, 'invalid_api_key', 'Secondary credentials expired.', null, FailureCategory::AUTHENTICATION),
            new SendResult(true, 'sent', 'Third provider accepted the message.'),
        ]);

        $result = $this->buildPipeline($adapter)->handlePreWpMail(null, [
            'to' => ['qa@example.com'],
            'subject' => 'Three-provider failover',
            'message' => 'Hello',
            'headers' => [],
        ]);

        self::assertTrue($result);
        $attempts = array_values(array_filter(
            $GLOBALS['wpdb']->inserts,
            static fn (array $insert): bool => str_ends_with($insert['table'], 'onesmtp_attempts')
        ));
        self::assertCount(3, $attempts);
        self::assertSame(['fail', 'fail', 'sent'], array_column(array_column($attempts, 'data'), 'result'));
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
        self::assertSame(FailureCategory::RETRYABLE, $attemptInsert['data']['failure_category']);
        self::assertIsInt($attemptInsert['data']['latency_ms']);
        self::assertGreaterThanOrEqual(0, $attemptInsert['data']['latency_ms']);

        $retryUpdate = $this->findUpdate('onesmtp_messages', 'retry_scheduled');
        self::assertNotNull($retryUpdate);
        self::assertSame(2, $retryUpdate['data']['current_attempt']);

        self::assertCount(1, $GLOBALS['onesmtp_test_scheduled_actions']);
        $retryEvent = $this->findEventInsert('retry_scheduled');
        self::assertNotNull($retryEvent);
    }

    public function test_rate_limit_exhaustion_defers_without_sending_provider_request(): void
    {
        $this->seedProvider(22, 'test_rate_limited');
        update_option('onesmtp_settings', [
            'rate_limits' => [
                'per_minute' => 1,
            ],
        ], false);
        $since = gmdate('Y-m-d H:i:s', 1782302400 - 60);
        $GLOBALS['wpdb']->successfulSendWindowStatsBySince[$since] = [
            'sent_count' => 1,
            'oldest_created_at' => gmdate('Y-m-d H:i:s', 1782302400 - 50),
        ];

        $adapter = new CountingAdapter('test_rate_limited', new SendResult(true, 'sent', 'sent'));
        $pipeline = $this->buildPipeline($adapter, static fn (): int => 1782302400);

        $before = time();
        $result = $pipeline->handlePreWpMail(null, [
            'to' => ['qa@example.com'],
            'subject' => 'Rate limited',
            'message' => 'Hello',
            'headers' => [],
        ]);
        $after = time();

        self::assertTrue($result);
        self::assertSame(0, $adapter->sendCount);
        self::assertNull($this->findInsert('onesmtp_attempts'));

        $retryUpdate = $this->findUpdate('onesmtp_messages', 'retry_scheduled');
        self::assertNotNull($retryUpdate);
        self::assertSame(1, $retryUpdate['data']['current_attempt']);

        self::assertCount(1, $GLOBALS['onesmtp_test_scheduled_actions']);
        $scheduled = array_values($GLOBALS['onesmtp_test_scheduled_actions'])[0];
        self::assertGreaterThanOrEqual($before + 10, $scheduled['timestamp']);
        self::assertLessThanOrEqual($after + 10, $scheduled['timestamp']);
        self::assertSame([1, 1, $this->messageUuidFromInsert()], $scheduled['args']);

        $deferredEvent = $this->findEventInsert('rate_limit_deferred');
        self::assertNotNull($deferredEvent);
        $context = json_decode((string) $deferredEvent['data']['context_json'], true);
        self::assertSame('minute', $context['window'] ?? null);
        self::assertSame(10, $context['retry_after'] ?? null);
    }

    public function test_background_mode_queues_initial_send_without_provider_latency(): void
    {
        $this->seedProvider(23, 'test_background');
        update_option('onesmtp_settings', [
            'background_sending' => [
                'enabled' => true,
            ],
        ], false);

        $adapter = new CountingAdapter('test_background', new SendResult(true, 'sent', 'sent'));
        $pipeline = $this->buildPipeline($adapter);

        $result = $pipeline->handlePreWpMail(null, [
            'to' => ['qa@example.com'],
            'subject' => 'Background queued',
            'message' => 'Hello',
            'headers' => [],
        ]);

        self::assertTrue($result);
        self::assertSame(0, $adapter->sendCount);
        self::assertNull($this->findInsert('onesmtp_attempts'));
        self::assertCount(1, $GLOBALS['onesmtp_test_scheduled_actions']);

        $scheduled = array_values($GLOBALS['onesmtp_test_scheduled_actions'])[0];
        self::assertSame(RetryScheduler::BACKGROUND_ACTION_HOOK, $scheduled['hook']);
        self::assertSame([1, 1, $this->messageUuidFromInsert()], $scheduled['args']);

        $event = $this->findEventInsert('background_send_queued');
        self::assertNotNull($event);
        $context = json_decode((string) $event['data']['context_json'], true);
        self::assertSame(1, $context['attempt'] ?? null);
        self::assertStringNotContainsString('qa@example.com', (string) $event['data']['context_json']);
        self::assertStringNotContainsString('Hello', (string) $event['data']['context_json']);
    }

    public function test_background_mode_sync_override_sends_immediately(): void
    {
        $this->seedProvider(24, 'test_background_sync');
        update_option('onesmtp_settings', [
            'background_sending' => [
                'enabled' => true,
            ],
        ], false);

        $adapter = new CountingAdapter('test_background_sync', new SendResult(true, 'sent', 'sent'));
        $pipeline = $this->buildPipeline($adapter);

        $result = $pipeline->handlePreWpMail(null, [
            'to' => ['qa@example.com'],
            'subject' => 'Urgent sync',
            'message' => 'Hello',
            'headers' => [],
            'onesmtp_send_mode' => 'sync',
        ]);

        self::assertTrue($result);
        self::assertSame(1, $adapter->sendCount);
        self::assertSame([], $GLOBALS['onesmtp_test_scheduled_actions']);
        self::assertSame('initial', $this->findInsert('onesmtp_attempts')['data']['trigger_type'] ?? null);
    }

    public function test_terminal_failure_category_marks_failed_without_scheduling_retry(): void
    {
        $this->seedProvider(25, 'test_terminal');

        $pipeline = $this->buildPipeline(
            new StaticAdapter(
                'test_terminal',
                new SendResult(false, 'missing_api_key', 'Provider API key is not configured.')
            )
        );

        $result = $pipeline->handlePreWpMail(null, [
            'to' => ['qa@example.com'],
            'subject' => 'Pipeline terminal failure',
            'message' => 'Hello',
            'headers' => [],
        ]);

        self::assertFalse($result);

        $attemptInsert = $this->findInsert('onesmtp_attempts');
        self::assertNotNull($attemptInsert);
        self::assertSame('missing_api_key', $attemptInsert['data']['error_code']);
        self::assertSame(FailureCategory::AUTHENTICATION, $attemptInsert['data']['failure_category']);

        self::assertSame([], $GLOBALS['onesmtp_test_scheduled_actions']);
        self::assertNull($this->findEventInsert('retry_scheduled'));

        $failedUpdate = $this->findUpdate('onesmtp_messages', 'failed');
        self::assertNotNull($failedUpdate);
        self::assertSame(1, $failedUpdate['data']['current_attempt']);

        $terminalEvent = $this->findEventInsert('terminal_failure');
        self::assertNotNull($terminalEvent);
        $context = json_decode((string) $terminalEvent['data']['context_json'], true);
        self::assertSame('missing_api_key', $context['reason'] ?? null);
        self::assertSame(FailureCategory::AUTHENTICATION, $context['failure_category'] ?? null);
    }

    public function test_message_scoped_terminal_failure_does_not_open_provider_circuit(): void
    {
        $this->seedProvider(27, 'terminal_message');
        $pipeline = $this->buildPipeline(new StaticAdapter(
            'terminal_message',
            new SendResult(false, 'invalid_recipient', 'Recipient is invalid.', null, FailureCategory::TERMINAL)
        ));

        self::assertFalse($pipeline->handlePreWpMail(null, [
            'to' => ['invalid@example.com'],
            'subject' => 'Invalid recipient',
            'message' => 'Hello',
            'headers' => [],
        ]));

        $providerUpdates = array_filter(
            $GLOBALS['wpdb']->updates,
            static fn (array $update): bool => str_ends_with($update['table'], 'onesmtp_providers')
        );
        self::assertSame([], array_values($providerUpdates));
    }

    public function test_terminal_failure_triggers_privacy_safe_alerts(): void
    {
        $this->seedProvider(26, 'test_alert_terminal');
        update_option('onesmtp_settings', [
            'failure_alerts' => [
                'email_enabled' => true,
                'email_recipients' => ['ops@example.test'],
                'webhook_enabled' => true,
                'webhook_url' => 'https://hooks.example.test/onesmtp',
                'throttle_seconds' => 900,
            ],
        ], false);

        $pipeline = $this->buildPipeline(
            new StaticAdapter(
                'test_alert_terminal',
                new SendResult(false, 'missing_api_key', 'Provider API key is not configured. token=secret-token')
            )
        );

        $result = $pipeline->handlePreWpMail(null, [
            'to' => ['private@example.test', 'second@example.test'],
            'subject' => 'Sensitive reset subject',
            'message' => 'Raw private message body',
            'headers' => ['Authorization: Bearer raw-header-token'],
        ]);

        self::assertFalse($result);
        self::assertCount(1, $GLOBALS['onesmtp_test_mail']);
        self::assertCount(1, $GLOBALS['onesmtp_test_remote_posts']);

        $mailBody = (string) $GLOBALS['onesmtp_test_mail'][0]['message'];
        $webhookBody = (string) ($GLOBALS['onesmtp_test_remote_posts'][0]['args']['body'] ?? '');
        self::assertStringContainsString('"event": "terminal_failure"', $mailBody);
        self::assertStringContainsString('"event":"terminal_failure"', $webhookBody);
        self::assertStringContainsString('missing_api_key', $mailBody);

        foreach ([$mailBody, $webhookBody] as $body) {
            self::assertStringNotContainsString('private@example.test', $body);
            self::assertStringNotContainsString('second@example.test', $body);
            self::assertStringNotContainsString('Sensitive reset subject', $body);
            self::assertStringNotContainsString('Raw private message body', $body);
            self::assertStringNotContainsString('raw-header-token', $body);
            self::assertStringNotContainsString('secret-token', $body);
            self::assertStringNotContainsString('payload_json', $body);
        }
    }

    public function test_real_terminal_failure_producer_dispatches_pro_escalation(): void
    {
        $this->seedProvider(27, 'test_alert_escalation');
        update_option('onesmtp_settings', [
            'failure_alerts' => [
                'advanced_enabled' => true,
                'advanced_destinations' => [
                    [
						'channel' => 'email',
						'target' => 'oncall@example.test',
					],
                ],
                'escalation_failure_threshold' => 1,
            ],
        ], false);

        $alerts = new FailureAlertDispatcher(
            null,
            null,
            new FeatureGate([
                FeatureGate::ADVANCED_ALERTS => true,
            ], true)
        );
        $pipeline = $this->buildPipeline(
            new StaticAdapter(
                'test_alert_escalation',
                new SendResult(false, 'missing_api_key', 'Provider API key is not configured.')
            ),
            null,
            new EventRepository($alerts)
        );

        self::assertFalse($pipeline->handlePreWpMail(null, [
            'to' => ['private@example.test'],
            'subject' => 'Terminal escalation',
            'message' => 'Sensitive body',
            'headers' => [],
        ]));

        self::assertCount(1, $GLOBALS['onesmtp_test_mail']);
        self::assertSame(['oncall@example.test'], $GLOBALS['onesmtp_test_mail'][0]['to']);
        self::assertStringContainsString('"alert_level": "escalated"', (string) $GLOBALS['onesmtp_test_mail'][0]['message']);
        self::assertStringContainsString('"trigger": "repeated_failures"', (string) $GLOBALS['onesmtp_test_mail'][0]['message']);
        self::assertStringNotContainsString('Sensitive body', (string) $GLOBALS['onesmtp_test_mail'][0]['message']);
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

    public function test_background_worker_records_success_path(): void
    {
        $this->seedProvider(31, 'test_background_worker_success');
        $GLOBALS['wpdb']->messageRowsById[601] = [
            'id' => 601,
            'message_uuid' => 'background-601',
            'payload_json' => wp_json_encode([
                'to' => ['qa@example.com'],
                'subject' => 'Queued success',
                'message' => 'Hello',
                'headers' => ['X-OneSMTP-Message-ID: background-601'],
            ]),
            'status' => 'queued',
            'max_attempts' => 6,
        ];

        $pipeline = $this->buildPipeline(new StaticAdapter('test_background_worker_success', new SendResult(true, 'sent', 'sent', 'provider-background-601')));

        $pipeline->handleBackgroundSendAttempt(601, 1, [], 'background-601');

        $attemptInsert = $this->findInsert('onesmtp_attempts');
        self::assertNotNull($attemptInsert);
        self::assertSame(601, $attemptInsert['data']['message_id']);
        self::assertSame('background', $attemptInsert['data']['trigger_type']);
        self::assertSame('sent', $attemptInsert['data']['result']);

        $sentUpdate = $this->findUpdate('onesmtp_messages', 'sent');
        self::assertNotNull($sentUpdate);
        self::assertSame(31, $sentUpdate['data']['selected_provider_id']);
    }

    public function test_background_worker_records_failure_and_schedules_retry(): void
    {
        $this->seedProvider(32, 'test_background_worker_failure');
        $GLOBALS['wpdb']->messageRowsById[602] = [
            'id' => 602,
            'message_uuid' => 'background-602',
            'payload_json' => wp_json_encode([
                'to' => ['qa@example.com'],
                'subject' => 'Queued failure',
                'message' => 'Hello',
                'headers' => ['X-OneSMTP-Message-ID: background-602'],
            ]),
            'status' => 'queued',
            'max_attempts' => 6,
        ];

        $pipeline = $this->buildPipeline(new StaticAdapter('test_background_worker_failure', new SendResult(false, 'provider_failed', 'failed')));

        $pipeline->handleBackgroundSendAttempt(602, 1, [], 'background-602');

        $attemptInsert = $this->findInsert('onesmtp_attempts');
        self::assertNotNull($attemptInsert);
        self::assertSame(602, $attemptInsert['data']['message_id']);
        self::assertSame('background', $attemptInsert['data']['trigger_type']);
        self::assertSame('fail', $attemptInsert['data']['result']);

        $retryUpdate = $this->findUpdate('onesmtp_messages', 'retry_scheduled');
        self::assertNotNull($retryUpdate);
        self::assertSame(2, $retryUpdate['data']['current_attempt']);

        $scheduled = array_values($GLOBALS['onesmtp_test_scheduled_actions'])[0] ?? null;
        self::assertIsArray($scheduled);
        self::assertSame(RetryScheduler::ACTION_HOOK, $scheduled['hook']);
        self::assertSame([602, 2, 'background-602'], $scheduled['args']);
    }

    public function test_simulation_mode_captures_without_selecting_or_contacting_a_provider(): void
    {
        update_option('onesmtp_settings', ['simulation_mode' => ['enabled' => true]], false);
        $pipeline = $this->buildPipeline(new StaticAdapter('unused', new SendResult(true, 'sent', 'should not run')));

        $result = $pipeline->handlePreWpMail(null, [
            'to' => ['qa@example.com'],
            'subject' => 'Simulation only',
            'message' => 'Hello',
            'headers' => [],
        ]);

        self::assertTrue($result);
        self::assertNotNull($this->findUpdate('onesmtp_messages', 'simulated'));
        self::assertNotNull($this->findEventInsert('message_simulated'));
        self::assertNull($this->findInsert('onesmtp_attempts'));
    }

    public function test_simulation_mode_stops_queued_retry_background_and_manual_delivery(): void
    {
        update_option('onesmtp_settings', ['simulation_mode' => ['enabled' => true]], false);
        $this->seedProvider(10, 'smtp');
        $payload = [
            'to' => ['qa@example.com'],
            'subject' => 'Queued before simulation',
            'message' => 'Hello',
            'headers' => [],
        ];
        $GLOBALS['wpdb']->messageRowsById[700] = [
            'id' => 700,
            'message_uuid' => 'simulation-700',
            'payload_json' => wp_json_encode($payload),
            'status' => 'retry_scheduled',
            'max_attempts' => 6,
        ];
        $adapter = new CountingAdapter('smtp', new SendResult(true, 'sent', 'Must not run.'));
        $pipeline = $this->buildPipeline($adapter);

        $pipeline->handleRetryAttempt(700, 2, 10);
        $pipeline->handleBackgroundSendAttempt(700, 2);
        self::assertTrue($pipeline->resendMessage(700, 10));

        self::assertSame(0, $adapter->sendCount);
        self::assertCount(3, array_filter(
            $GLOBALS['wpdb']->updates,
            static fn (array $update): bool => ($update['data']['status'] ?? '') === 'simulated'
        ));
    }

    public function test_attachment_mail_is_sent_synchronously_and_never_queued_for_lossy_retry(): void
    {
        update_option('onesmtp_settings', ['background_sending' => ['enabled' => true]], false);
        $this->seedProvider(10, 'smtp');
        $adapter = new CountingAdapter('smtp', new SendResult(false, 'provider_timeout', 'Timed out.', null, FailureCategory::TIMEOUT));
        $pipeline = $this->buildPipeline($adapter);

        $result = $pipeline->handlePreWpMail(null, [
            'to' => ['qa@example.com'],
            'subject' => 'Attachment safety',
            'message' => 'Hello',
            'headers' => [],
            'attachments' => ['/tmp/invoice.pdf'],
        ]);

        self::assertFalse($result);
        self::assertSame(1, $adapter->sendCount);
        self::assertSame([], $GLOBALS['onesmtp_test_scheduled_actions']);
        self::assertNotNull($this->findEventInsert('terminal_failure'));
    }

    private function buildPipeline(ProviderAdapterInterface $adapter, ?callable $clock = null, ?EventRepository $events = null): SendPipeline
    {
        $dispatch = new DefaultDispatchPolicy();
        $messages = new MessageRepository();
        $attempts = new AttemptRepository();
        $providers = new ProviderRepository();
        $events = $events ?? new EventRepository();
        $retryScheduler = new RetryScheduler($dispatch, $messages, $attempts, $providers, $events);
        $deliveryManager = new ProviderDeliveryManager(new ProviderAdapterRegistry([$adapter->getSlug() => $adapter]));
        $deliveryEngine = new DeliveryEngine($providers, $attempts, $dispatch, $deliveryManager, $events);
        $rateLimiter = new RateLimiter($attempts, new RateLimitSettingsRepository(), $clock);

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

    /** @param array<int,array{id:int,adapter_type:string,priority:int}> $providers */
    private function seedProviders(array $providers): void
    {
        $GLOBALS['wpdb']->activeProviders = [];
        foreach ($providers as $provider) {
            $row = $provider + [
                'is_active' => 1,
                'weight' => 1,
                'config_json' => '{}',
            ];
            $GLOBALS['wpdb']->activeProviders[] = $row;
            $GLOBALS['wpdb']->providerRowsById[(int) $row['id']] = $row;
        }
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

    private function messageUuidFromInsert(): string
    {
        $insert = $this->findInsert('onesmtp_messages');

        return is_array($insert) ? (string) ($insert['data']['message_uuid'] ?? '') : '';
    }
}

class StaticAdapter implements ProviderAdapterInterface
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

final class CountingAdapter extends StaticAdapter
{
    public int $sendCount = 0;

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $this->sendCount++;

        return parent::send($message, $config);
    }
}

final class SequenceAdapter implements ProviderAdapterInterface
{
    private int $index = 0;

    /** @param array<int,SendResult> $results */
    public function __construct(private string $slug, private array $results)
    {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $result = $this->results[min($this->index, count($this->results) - 1)];
        $this->index++;

        return $result;
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->results[0];
    }
}
