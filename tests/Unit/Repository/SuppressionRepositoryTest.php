<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Repository;

use OneSMTP\Repository\SuppressionRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class SuppressionRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
    }

    public function test_list_active_excludes_expired_rows_and_bounds_the_limit(): void
    {
        $GLOBALS['wpdb']->suppressionRowsByFingerprint = [
            'a' => [
                'id' => 1,
                'recipient_fingerprint' => 'a',
                'recipient_domain' => 'expired.example.test',
                'expiry_at' => '2026-08-09 23:59:59',
            ],
            'b' => [
                'id' => 2,
                'recipient_fingerprint' => 'b',
                'recipient_domain' => 'active.example.test',
                'expiry_at' => '2026-08-10 00:00:01',
            ],
        ];

        $rows = (new SuppressionRepository())->listActive('2026-08-10 00:00:00', 999);

        self::assertCount(1, $rows);
        self::assertSame('active.example.test', $rows[0]['recipient_domain']);
        self::assertStringContainsString('expiry_at > %s', $GLOBALS['wpdb']->lastPrepared['query'] ?? '');
    }

    public function test_remove_reports_actual_affected_rows(): void
    {
        $GLOBALS['wpdb']->suppressionRowsByFingerprint['fingerprint'] = [
            'id' => 1,
            'recipient_fingerprint' => 'fingerprint',
        ];
        $repository = new SuppressionRepository();

        self::assertTrue($repository->remove('fingerprint'));
        self::assertFalse($repository->remove('fingerprint'));
    }

    public function test_upsert_does_not_persist_provider_message_id(): void
    {
        $fingerprint = str_repeat('a', 64);
        self::assertTrue(
            (new SuppressionRepository())->upsert(
                $fingerprint,
                'example.test',
                'hard_bounce',
                'mailgun',
                null,
                '2026-08-10 00:00:00',
                '2026-09-09 00:00:00'
            )
        );

        $query = $GLOBALS['wpdb']->lastPrepared['query'] ?? '';
        self::assertStringNotContainsString('provider_message_id', $query);
        self::assertArrayNotHasKey('provider_message_id', $GLOBALS['wpdb']->suppressionRowsByFingerprint[ $fingerprint ] ?? []);
    }
}
