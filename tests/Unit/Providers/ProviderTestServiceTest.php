<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Providers;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderDeliveryManager;
use OneSMTP\Providers\ProviderTestService;
use OneSMTP\Providers\SendResult;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderTestServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_actions'] = [];
        $GLOBALS['onesmtp_test_filters'] = [];
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_object_cache'] = [];
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /** @return array<string,array{0:SendResult,1:string}> */
    public static function outcomes(): array
    {
        return [
            'success' => [new SendResult(true, 'sent', 'Accepted.'), 'sent'],
            'failure' => [new SendResult(false, 'provider_timeout', 'Timed out.'), 'fail'],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_provider_test_persists_measured_latency_for_success_and_failure(
        SendResult $result,
        string $expectedAttemptResult
    ): void {
        $adapter = new class($result) implements ProviderAdapterInterface {
            public function __construct(private SendResult $result)
            {
            }

            public function getSlug(): string
            {
                return 'timed-test';
            }

            public function send(array $message, ProviderConfig $config): SendResult
            {
                return $this->result;
            }

            public function testConnection(ProviderConfig $config): SendResult
            {
                return $this->result;
            }
        };
        $ticks = [2_000_000_000, 2_250_000_000];
        $service = new ProviderTestService(
            new MessageRepository(),
            new AttemptRepository(),
            new EventRepository(),
            new ProviderDeliveryManager(new ProviderAdapterRegistry(['timed-test' => $adapter])),
            null,
            null,
            static function () use (&$ticks): int {
                return (int) array_shift($ticks);
            }
        );

        $service->send(
            ['id' => 91, 'adapter_type' => 'timed-test', 'config' => []],
            ['to' => ['qa@example.com'], 'subject' => 'Provider test', 'message' => 'Hello', 'headers' => []]
        );

        $attempt = $this->findAttemptInsert();
        self::assertNotNull($attempt);
        self::assertSame($expectedAttemptResult, $attempt['data']['result']);
        self::assertSame(250, $attempt['data']['latency_ms']);
    }

    /** @return array{table:string,data:array<string,mixed>,format:array<int,string>}|null */
    private function findAttemptInsert(): ?array
    {
        foreach ($GLOBALS['wpdb']->inserts as $insert) {
            if (str_ends_with($insert['table'], 'onesmtp_attempts')) {
                return $insert;
            }
        }

        return null;
    }
}
