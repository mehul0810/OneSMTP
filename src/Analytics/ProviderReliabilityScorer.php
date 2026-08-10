<?php

declare(strict_types=1);

namespace OneSMTP\Analytics;

/**
 * Converts aggregate provider outcomes into a bounded operational score.
 *
 * The score describes Aculect Mail's recorded attempt history only. It is not
 * an inbox-placement or provider SLA guarantee.
 */
final class ProviderReliabilityScorer
{
    public const CONFIDENCE_LIMITED = 'limited';
    public const CONFIDENCE_ESTABLISHED = 'established';

    private const ESTABLISHED_SAMPLE_SIZE = 20;

    /**
     * @param array{sent_count?:int,failed_count?:int,retry_count?:int,switch_out_count?:int,avg_latency_ms?:int|null} $provider
     *
     * @return array{score:int,success_rate:float,attempt_count:int,retry_rate:float,switch_rate:float,avg_latency_ms:?int,confidence:string}
     */
    public function score(array $provider): array
    {
        $sent = max(0, (int) ($provider['sent_count'] ?? 0));
        $failed = max(0, (int) ($provider['failed_count'] ?? 0));
        $retries = max(0, (int) ($provider['retry_count'] ?? 0));
        $switches = max(0, (int) ($provider['switch_out_count'] ?? 0));
        $attempts = $sent + $failed;
        $latency = isset($provider['avg_latency_ms'])
            ? max(0, (int) $provider['avg_latency_ms'])
            : null;

        if ($attempts === 0) {
            return [
                'score' => 0,
                'success_rate' => 0.0,
                'attempt_count' => 0,
                'retry_rate' => 0.0,
                'switch_rate' => 0.0,
                'avg_latency_ms' => $latency,
                'confidence' => self::CONFIDENCE_LIMITED,
            ];
        }

        $successRate = ($sent / $attempts) * 100;
        $retryRate = min(100.0, ($retries / $attempts) * 100);
        $switchRate = min(100.0, ($switches / $attempts) * 100);
        $retryPenalty = min(15.0, $retryRate * 0.15);
        $switchPenalty = min(20.0, $switchRate * 0.40);
        $latencyPenalty = $this->latencyPenalty($latency);
        $score = (int) round(max(0.0, min(100.0, $successRate - $retryPenalty - $switchPenalty - $latencyPenalty)));

        return [
            'score' => $score,
            'success_rate' => round($successRate, 1),
            'attempt_count' => $attempts,
            'retry_rate' => round($retryRate, 1),
            'switch_rate' => round($switchRate, 1),
            'avg_latency_ms' => $latency,
            'confidence' => $attempts >= self::ESTABLISHED_SAMPLE_SIZE
                ? self::CONFIDENCE_ESTABLISHED
                : self::CONFIDENCE_LIMITED,
        ];
    }

    private function latencyPenalty(?int $latency): float
    {
        if ($latency === null || $latency <= 1000) {
            return 0.0;
        }

        if ($latency <= 3000) {
            return 5.0;
        }

        if ($latency <= 5000) {
            return 10.0;
        }

        return 15.0;
    }
}
