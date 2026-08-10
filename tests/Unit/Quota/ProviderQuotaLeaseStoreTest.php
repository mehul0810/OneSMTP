<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Quota;

use OneSMTP\Quota\ProviderQuotaLeaseStore;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class ProviderQuotaLeaseStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_expired_lock_takeover_and_old_owner_release_are_fenced(): void
    {
        $now = 1000;
        $first = $this->store($now, ['lock-a']);
        $second = $this->store($now, ['lock-b-race', 'lock-b']);

        self::assertSame('lock-a', $first->acquireLock('provider_quota_send_lock', 60));
        self::assertNull($second->acquireLock('provider_quota_send_lock', 60));

        $now = 1061;
        self::assertSame('lock-b', $second->acquireLock('provider_quota_send_lock', 60));
        self::assertFalse($first->releaseLock('provider_quota_send_lock', 'lock-a'));
        self::assertTrue($second->releaseLock('provider_quota_send_lock', 'lock-b'));
    }

    public function test_reservations_use_tokens_without_lost_increments_or_oversubscription(): void
    {
        $now = 2000;
        $first = $this->store($now, ['reservation-a']);
        $second = $this->store($now, ['reservation-b']);

        self::assertSame('reservation-a', $first->reserveProvider(7, 120));
        self::assertSame('reservation-b', $second->reserveProvider(7, 120));
        self::assertSame(2, $first->countReservations(7));
        self::assertTrue($first->releaseProviderReservation(7, 'reservation-a'));
        self::assertSame(1, $second->countReservations(7));
        self::assertFalse($second->releaseProviderReservation(7, 'reservation-a'));
        self::assertSame(1, $second->countReservations(7));
    }

    public function test_expired_reservation_cleanup_cannot_delete_new_token(): void
    {
        $now = 3000;
        $first = $this->store($now, ['reservation-old']);
        $second = $this->store($now, ['reservation-new']);

        self::assertSame('reservation-old', $first->reserveProvider(11, 120));
        $now = 3121;
        self::assertSame('reservation-new', $second->reserveProvider(11, 120));
        self::assertSame(1, $first->countReservations(11));
        self::assertFalse($first->releaseProviderReservation(11, 'reservation-old'));
        self::assertSame(1, $second->countReservations(11));
        self::assertTrue($second->releaseProviderReservation(11, 'reservation-new'));
        self::assertSame(0, $first->countReservations(11));
    }

    /** @param array<int,string> $tokens */
    private function store(int &$now, array $tokens): ProviderQuotaLeaseStore
    {
        $tokenGenerator = static function () use (&$tokens): string {
            return (string) array_shift($tokens);
        };

        return new ProviderQuotaLeaseStore(static function () use (&$now): int {
            return $now;
        }, $tokenGenerator);
    }
}
