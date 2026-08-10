<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Dispatch;

use OneSMTP\Dispatch\RoutingContextBuilder;
use OneSMTP\Dispatch\RoutingRuleNormalizer;
use PHPUnit\Framework\TestCase;

final class RoutingContextBuilderTest extends TestCase
{
    public function test_builds_normalized_addresses_and_source_fields_without_raw_payload_keys(): void
    {
        $context = (new RoutingContextBuilder())->fromPayload([
            'from' => 'Billing Team <BILLING@example.test>',
            'to' => ['person@example.test', 'Copy <copy@example.test>'],
            'cc' => 'audit@example.test',
            'subject' => 'Synthetic subject',
            'message' => 'Synthetic message body',
            'onesmtp_source' => [
                'type' => 'plugin',
                'slug' => 'checkout',
                'name' => 'Checkout Mailer',
                'metadata' => ['order_id' => 'fixture-1'],
            ],
            'headers' => ['X-Private' => 'never copied'],
        ]);

        self::assertSame(['billing@example.test'], $context['sender']);
        self::assertSame(['person@example.test', 'copy@example.test', 'audit@example.test'], $context['recipient']);
        self::assertSame('checkout', $context['source']);
        self::assertSame('plugin', $context['source_type']);
        self::assertArrayNotHasKey('message', $context);
        self::assertArrayNotHasKey('headers', $context);
    }

    public function test_content_is_bounded_to_the_in_memory_match_window(): void
    {
        $context = (new RoutingContextBuilder())->fromPayload([
            'message' => str_repeat('x', RoutingRuleNormalizer::MAX_MATCH_LENGTH + 100),
        ]);

        self::assertSame(RoutingRuleNormalizer::MAX_MATCH_LENGTH, strlen( (string) $context['content']));
    }
}
