<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Repository;

use OneSMTP\Repository\SuppressionDerivationRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class SuppressionDerivationRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_claim_is_atomic_and_pending_retry_can_complete_once(): void
    {
        $repository = new SuppressionDerivationRepository();
        $hash = str_repeat('a', 64);

        self::assertSame(SuppressionDerivationRepository::CLAIMED, $repository->claim($hash, '2026-08-10 00:00:00'));
        self::assertSame(SuppressionDerivationRepository::BUSY, $repository->claim($hash, '2026-08-10 00:00:01'));
        self::assertTrue($repository->markPending($hash, '2026-08-10 00:00:02'));
        self::assertSame(SuppressionDerivationRepository::CLAIMED, $repository->claim($hash, '2026-08-10 00:00:03'));
        self::assertTrue($repository->markProcessed($hash, '2026-08-10 00:00:04'));
        self::assertSame(SuppressionDerivationRepository::PROCESSED, $repository->claim($hash, '2026-08-10 00:00:05'));
    }
}
