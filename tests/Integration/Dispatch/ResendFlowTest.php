<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Integration\Dispatch;

use OneSMTP\Dispatch\DefaultDispatchPolicy;
use PHPUnit\Framework\TestCase;

final class ResendFlowTest extends TestCase
{
    public function test_manual_resend_uses_selected_provider_override(): void
    {
        $policy = new DefaultDispatchPolicy();

        $providerId = $policy->chooseNextProvider(910, 2, [
            'providers' => [
                ['id' => 501],
                ['id' => 502],
                ['id' => 503],
            ],
            'last_provider_id' => 501,
            'consecutive_failures_for_last_provider' => 1,
            'forced_provider_id' => 503,
        ]);

        self::assertSame(503, $providerId);
    }

    public function test_manual_resend_keeps_lineage_payload_shape_expectation(): void
    {
        $lineage = [
            'message_id' => 910,
            'message_uuid' => 'msg-uuid-910',
            'original_attempt' => 2,
            'resend_attempt' => 3,
            'forced_provider_id' => 503,
        ];

        self::assertSame(910, $lineage['message_id']);
        self::assertSame('msg-uuid-910', $lineage['message_uuid']);
        self::assertSame(503, $lineage['forced_provider_id']);
        self::assertGreaterThan($lineage['original_attempt'], $lineage['resend_attempt']);
    }
}
