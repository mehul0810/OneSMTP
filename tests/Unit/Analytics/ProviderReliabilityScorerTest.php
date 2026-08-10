<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Analytics;

use OneSMTP\Analytics\ProviderReliabilityScorer;
use PHPUnit\Framework\TestCase;

final class ProviderReliabilityScorerTest extends TestCase
{
    public function test_score_rewards_successful_low_latency_history(): void
    {
        $result = (new ProviderReliabilityScorer())->score([
            'sent_count' => 96,
            'failed_count' => 4,
            'retry_count' => 2,
            'switch_out_count' => 1,
            'avg_latency_ms' => 800,
        ]);

        self::assertSame(95, $result['score']);
        self::assertSame(96.0, $result['success_rate']);
        self::assertSame(100, $result['attempt_count']);
        self::assertSame(ProviderReliabilityScorer::CONFIDENCE_ESTABLISHED, $result['confidence']);
    }

    public function test_retries_switches_and_latency_reduce_the_score_predictably(): void
    {
        $result = (new ProviderReliabilityScorer())->score([
            'sent_count' => 16,
            'failed_count' => 4,
            'retry_count' => 8,
            'switch_out_count' => 5,
            'avg_latency_ms' => 4200,
        ]);

        self::assertSame(54, $result['score']);
        self::assertSame(80.0, $result['success_rate']);
        self::assertSame(40.0, $result['retry_rate']);
        self::assertSame(25.0, $result['switch_rate']);
        self::assertSame(4200, $result['avg_latency_ms']);
    }

    public function test_empty_and_small_samples_remain_limited(): void
    {
        $scorer = new ProviderReliabilityScorer();

        self::assertSame([
            'score' => 0,
            'success_rate' => 0.0,
            'attempt_count' => 0,
            'retry_rate' => 0.0,
            'switch_rate' => 0.0,
            'avg_latency_ms' => null,
            'confidence' => ProviderReliabilityScorer::CONFIDENCE_LIMITED,
        ], $scorer->score([]));
        self::assertSame(
            ProviderReliabilityScorer::CONFIDENCE_LIMITED,
            $scorer->score([
                'sent_count' => 9,
                'failed_count' => 1,
            ])['confidence']
        );
    }
}
