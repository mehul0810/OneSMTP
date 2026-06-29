<?php

declare(strict_types=1);

namespace OneSMTP\Tests\E2E\Admin;

use OneSMTP\Admin\LogAdmin;
use OneSMTP\Admin\SetupWizard;
use OneSMTP\Core\Capabilities;
use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\SendResult;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeliverabilitySmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_object_cache'] = [];
        $GLOBALS['onesmtp_test_options'] = [
            'admin_email' => [
                'value' => 'admin@example.test',
                'autoload' => true,
            ],
        ];
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            Capabilities::MANAGE_PLUGIN => true,
            Capabilities::VIEW_LOGS => true,
            Capabilities::RESEND_EMAILS => true,
            'manage_options' => false,
        ];

        unset($GLOBALS['onesmtp_test_redirect'], $GLOBALS['onesmtp_test_wp_die']);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['onesmtp_test_current_user_caps'],
            $GLOBALS['onesmtp_test_object_cache'],
            $GLOBALS['onesmtp_test_options'],
            $GLOBALS['onesmtp_test_redirect'],
            $GLOBALS['onesmtp_test_wp_die']
        );

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        parent::tearDown();
    }

    public function test_admin_setup_test_send_logs_and_resend_smoke_flow(): void
    {
        $providers = new ProviderRepository();
        $wizard = new SetupWizard($providers, null, new EventRepository());

        $emptySetup = $this->renderSetup($wizard);

        self::assertStringContainsString('Needs setup', $emptySetup);
        self::assertStringContainsString('Save first provider', $emptySetup);
        self::assertStringContainsString('Add and activate a provider before sending a setup test email.', $emptySetup);

        $this->postSetupProvider();
        $this->expectRedirect('OneSMTP setup wizard redirected.', static function () use ($wizard): void {
            $wizard->handleRequest();
        });

        $providerRow = $this->persistSavedProviderRow();

        self::assertStringContainsString('onesmtp_setup_status=provider_saved', (string) $GLOBALS['onesmtp_test_redirect']['location']);
        self::assertSame('setup_provider_saved', $GLOBALS['wpdb']->inserts[1]['data']['event_type']);

        $adapter = new AdminSmokeAdapter(new SendResult(true, 'accepted', 'Accepted by provider.'));
        $delivery = new ProviderDeliveryManager(new ProviderAdapterRegistry(['smtp' => $adapter]));
        $testWizard = new SetupWizard($providers, $delivery, new EventRepository());

        $this->postSetupTestSend((int) $providerRow['id']);
        $this->expectRedirect('OneSMTP setup wizard redirected.', static function () use ($testWizard): void {
            $testWizard->handleRequest();
        });

        self::assertSame(['recipient@example.test'], $adapter->lastMessage['to'] ?? []);
        self::assertStringContainsString('onesmtp_setup_status=test_sent', (string) $GLOBALS['onesmtp_test_redirect']['location']);
        self::assertSame('setup_test_email', $GLOBALS['wpdb']->inserts[2]['data']['event_type']);

        $_GET = ['onesmtp_setup_status' => 'test_sent'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $completedSetup = $this->renderSetup($testWizard);

        self::assertStringContainsString('Test email sent. Setup actions are being recorded in the event log.', $completedSetup);
        self::assertStringContainsString('Complete', $completedSetup);
        self::assertStringNotContainsString('credential-value', $completedSetup);

        $this->seedMessageLogRows((int) $providerRow['id']);

        $_GET = ['onesmtp_message_id' => '21'];
        $logAdmin = new LogAdmin(
            new MessageRepository(),
            new AttemptRepository(),
            $providers,
            null,
            static function (int $messageId, ?int $providerId): bool {
                $GLOBALS['onesmtp_test_resend_call'] = [$messageId, $providerId];

                return true;
            }
        );

        $logDetail = $this->renderLogs($logAdmin);

        self::assertStringContainsString('Recent messages', $logDetail);
        self::assertStringContainsString('Message detail', $logDetail);
        self::assertStringContainsString('Manual resend', $logDetail);
        self::assertStringContainsString('Smoke SMTP (smtp)', $logDetail);
        self::assertStringContainsString('1 recipients across example.test', $logDetail);
        self::assertStringContainsString('transient timeout', $logDetail);
        self::assertStringNotContainsString('recipient@example.test', $logDetail);
        self::assertStringNotContainsString('Internal smoke body', $logDetail);

        $this->postManualResend((int) $providerRow['id']);
        $this->expectRedirect('OneSMTP log admin redirected.', static function () use ($logAdmin): void {
            $logAdmin->handleRequest();
        });

        self::assertSame([21, (int) $providerRow['id']], $GLOBALS['onesmtp_test_resend_call']);
        self::assertStringContainsString('onesmtp_resend_status=resent', (string) $GLOBALS['onesmtp_test_redirect']['location']);

        $_GET = [
            'onesmtp_message_id' => '21',
            'onesmtp_resend_status' => 'resent',
        ];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $resentLogDetail = $this->renderLogs($logAdmin);

        self::assertStringContainsString('Manual resend completed.', $resentLogDetail);
    }

    private function renderSetup(SetupWizard $wizard): string
    {
        ob_start();
        $wizard->render();

        return (string) ob_get_clean();
    }

    private function renderLogs(LogAdmin $admin): string
    {
        ob_start();
        try {
            $admin->render();

            return (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw $throwable;
        }
    }

    private function postSetupProvider(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_setup_action' => 'save_provider',
            'onesmtp_setup_nonce' => 'test-nonce',
            'from_email' => 'admin@example.test',
            'from_name' => 'Admin Sender',
            'name' => 'Smoke SMTP',
            'adapter_type' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => '2525',
            'username' => 'smoke-user',
        ];
    }

    private function postSetupTestSend(int $providerId): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_setup_action' => 'send_test',
            'onesmtp_setup_nonce' => 'test-nonce',
            'provider_id' => (string) $providerId,
            'test_to' => 'recipient@example.test',
        ];
    }

    private function postManualResend(int $providerId): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_log_action' => 'resend',
            'onesmtp_log_nonce' => 'test-nonce',
            'onesmtp_message_id' => '21',
            'provider_id' => (string) $providerId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function persistSavedProviderRow(): array
    {
        $providerRow = $GLOBALS['wpdb']->inserts[0]['data'];
        $providerRow['id'] = 1;

        $GLOBALS['wpdb']->providerRowsById[1] = $providerRow;
        $GLOBALS['wpdb']->activeProviders = [$providerRow];
        $GLOBALS['onesmtp_test_object_cache'] = [];

        return $providerRow;
    }

    private function seedMessageLogRows(int $providerId): void
    {
        $message = [
            'id' => 21,
            'message_uuid' => 'lineage-smoke-21',
            'payload_json' => wp_json_encode(
                [
                    'to' => ['recipient@example.test'],
                    'subject' => 'Smoke subject',
                    'message' => 'Internal smoke body',
                ]
            ),
            'status' => 'failed',
            'selected_provider_id' => $providerId,
            'current_attempt' => 1,
            'max_attempts' => 6,
            'created_at' => '2026-06-24 10:00:00',
            'updated_at' => '2026-06-24 10:01:00',
        ];

        $GLOBALS['wpdb']->messageRowsById[21] = $message;
        $GLOBALS['wpdb']->recentMessageRows = [$message + ['attempt_count' => 1]];
        $GLOBALS['wpdb']->attemptHistoryByMessage[21] = [
            [
                'id' => 31,
                'message_id' => 21,
                'attempt_no' => 1,
                'provider_id' => $providerId,
                'trigger_type' => 'initial',
                'result' => 'fail',
                'error_code' => 'provider_timeout',
                'error_message' => str_repeat('transient timeout ', 18),
                'latency_ms' => 900,
                'provider_message_id' => 'provider-message-21',
                'created_at' => '2026-06-24 10:01:00',
            ],
        ];
    }

    private function expectRedirect(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected admin redirect exception.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}

final class AdminSmokeAdapter implements ProviderAdapterInterface
{
    /** @var array<string,mixed> */
    public array $lastMessage = [];

    /** @var array<string,mixed> */
    public array $lastConfig = [];

    public function __construct(private SendResult $result)
    {
    }

    public function getSlug(): string
    {
        return 'smtp';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $this->lastMessage = $message;
        $this->lastConfig = $config->all();

        return $this->result;
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        $this->lastConfig = $config->all();

        return $this->result;
    }
}
