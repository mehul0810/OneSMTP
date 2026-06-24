<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Providers;

use OneSMTP\Providers\FailureCategory;
use OneSMTP\Providers\FailureClassifier;
use OneSMTP\Providers\SendResult;
use PHPUnit\Framework\TestCase;

final class FailureClassifierTest extends TestCase
{
    /**
     * @dataProvider representativeFailures
     */
    public function test_provider_failures_map_to_normalized_categories(string $code, string $message, ?int $status, string $category): void
    {
        self::assertSame($category, FailureClassifier::classify($code, $message, $status));
    }

    public function test_send_result_defaults_to_classified_safe_category(): void
    {
        $result = new SendResult(false, 'missing_api_key', 'Provider API key is not configured.');

        self::assertSame(FailureCategory::AUTHENTICATION, $result->getFailureCategory());
        self::assertFalse($result->shouldRetry());
    }

    public static function representativeFailures(): array
    {
        return [
            'api auth failure' => ['sendgrid_api_error', '{"errors":[{"message":"Unauthorized"}]}', 401, FailureCategory::AUTHENTICATION],
            'rate limited provider' => ['postmark_api_error', 'Too many requests', 429, FailureCategory::QUOTA],
            'gateway timeout' => ['brevo_api_error', 'Gateway timeout', 504, FailureCategory::TIMEOUT],
            'provider outage' => ['sendgrid_api_error', 'Service unavailable', 503, FailureCategory::RETRYABLE],
            'invalid recipient' => ['invalid_recipient', 'No valid recipient found.', null, FailureCategory::TERMINAL],
            'policy rejection' => ['provider_rejected', 'Message blocked by provider policy.', null, FailureCategory::POLICY],
        ];
    }
}
