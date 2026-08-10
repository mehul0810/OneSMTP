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

        $firstToken = $repository->claim($hash, '2026-08-10 00:00:00');
        self::assertNotSame(SuppressionDerivationRepository::CLAIMED, $firstToken);
        self::assertNotSame(SuppressionDerivationRepository::BUSY, $firstToken);
        self::assertSame(64, strlen($firstToken));
        self::assertSame(SuppressionDerivationRepository::BUSY, $repository->claim($hash, '2026-08-10 00:00:01'));
        self::assertTrue($repository->markPending($hash, $firstToken, '2026-08-10 00:00:02'));
        $secondToken = $repository->claim($hash, '2026-08-10 00:00:03');
        self::assertNotSame($firstToken, $secondToken);
        self::assertTrue($repository->markProcessed($hash, $secondToken, '2026-08-10 00:00:04'));
        self::assertSame(SuppressionDerivationRepository::PROCESSED, $repository->claim($hash, '2026-08-10 00:00:05'));
    }

    public function test_stale_worker_cannot_finalize_after_reclaim(): void
    {
        $repository = new SuppressionDerivationRepository();
        $hash = str_repeat('b', 64);

        $workerAToken = $repository->claim($hash, '2026-08-10 00:00:00');
        $workerBToken = $repository->claim($hash, '2026-08-10 00:06:00');

        self::assertNotSame($workerAToken, $workerBToken);
        self::assertFalse($repository->markProcessed($hash, $workerAToken, '2026-08-10 00:06:01'));
        self::assertFalse($repository->markPending($hash, $workerAToken, '2026-08-10 00:06:02'));
        self::assertTrue($repository->markProcessed($hash, $workerBToken, '2026-08-10 00:06:03'));
        self::assertSame('processed', $GLOBALS['wpdb']->suppressionDerivationRowsByHash[ $hash ]['status'] ?? null);
        self::assertNull($GLOBALS['wpdb']->suppressionDerivationRowsByHash[ $hash ]['claim_token'] ?? null);
    }
}
