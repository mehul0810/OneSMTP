<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\DashboardAdmin;
use OneSMTP\Analytics\ProviderReliabilityScorer;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\MetricsRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DashboardAdminTest extends TestCase
{
    private const NOW = 1782302400;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            'view_onesmtp_logs' => true,
            'manage_options' => false,
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['onesmtp_test_current_user_caps'], $GLOBALS['onesmtp_test_wp_die']);

        parent::tearDown();
    }

    public function test_render_outputs_clear_empty_state_without_sensitive_fields(): void
    {
        $GLOBALS['wpdb']->dashboardActivityRowsBySince[$this->sinceDaysAgo(1)] = [
            'sent_count' => 0,
            'failed_count' => 0,
            'retry_count' => 0,
            'payload_json' => '{"message":"secret body","token":"secret-token"}',
        ];
        $GLOBALS['wpdb']->dashboardActivityRowsBySince[$this->sinceDaysAgo(7)] = [
            'sent_count' => 0,
            'failed_count' => 0,
            'retry_count' => 0,
        ];

        $html = $this->render();

        self::assertStringContainsString('No delivery data yet', $html);
        self::assertStringContainsString('Delivery trends and provider comparisons will appear here automatically.', $html);
        self::assertStringContainsString('Connect a provider', $html);
        self::assertStringNotContainsString('onesmtp-analytics-kpis', $html);
        self::assertStringNotContainsString('secret body', $html);
        self::assertStringNotContainsString('secret-token', $html);
    }

    public function test_render_outputs_activity_pending_and_truncated_provider_breakdown(): void
    {
        $lastDay = $this->sinceDaysAgo(1);
        $lastWeek = $this->sinceDaysAgo(7);
        $longName = str_repeat('LongProviderName', 8);

        $GLOBALS['wpdb']->dashboardActivityRowsBySince[$lastDay] = [
            'sent_count' => 3,
            'failed_count' => 2,
            'retry_count' => 1,
        ];
        $GLOBALS['wpdb']->dashboardFailoverCountsBySince[$lastDay] = 1;
        $GLOBALS['wpdb']->dashboardActivityRowsBySince[$lastWeek] = [
            'sent_count' => 30,
            'failed_count' => 4,
            'retry_count' => 6,
        ];
        $GLOBALS['wpdb']->dashboardFailoverCountsBySince[$lastWeek] = 5;
        $GLOBALS['wpdb']->dashboardPendingRow = [
            'queued_count' => 2,
            'retry_scheduled_count' => 4,
            'retrying_count' => 1,
        ];
        $GLOBALS['wpdb']->dashboardProviderAttemptRowsBySince[$lastWeek] = [
            [
                'provider_id' => 10,
                'provider_name' => $longName,
                'adapter_type' => 'smtp',
                'sent_count' => 30,
                'failed_count' => 4,
                'retry_count' => 6,
            ],
        ];
        $GLOBALS['wpdb']->dashboardProviderFailoverRowsBySince[$lastWeek] = [
            [
                'provider_id' => 10,
                'provider_name' => $longName,
                'adapter_type' => 'smtp',
                'failover_count' => 5,
            ],
        ];

        $html = $this->render();

        self::assertStringContainsString('Last 24 hours', $html);
        self::assertStringContainsString('<td>3</td>', $html);
        self::assertStringContainsString('<td>2</td>', $html);
        self::assertStringContainsString('<td>1</td>', $html);
        self::assertStringContainsString('Total pending</th><td>7</td>', $html);
        self::assertStringContainsString('Provider activity', $html);
        self::assertStringContainsString(esc_attr($longName), $html);
        self::assertStringContainsString(esc_html(substr($longName, 0, 77) . '...'), $html);
    }

    public function test_render_denies_users_without_log_visibility_capability(): void
    {
        $GLOBALS['onesmtp_test_current_user_caps'] = [
            'view_onesmtp_logs' => false,
            'manage_options' => false,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You do not have permission to view Aculect Mail dashboard metrics.');

        $this->render();
    }

    public function test_pro_reliability_dashboard_explains_score_and_evidence(): void
    {
        $lastWeek = $this->sinceDaysAgo(7);
        $GLOBALS['wpdb']->dashboardActivityRowsBySince[$lastWeek] = [
            'sent_count' => 96,
            'failed_count' => 4,
            'retry_count' => 2,
        ];
        $GLOBALS['wpdb']->dashboardProviderAttemptRowsBySince[$lastWeek] = [[
            'provider_id' => 10,
            'provider_name' => 'Primary SMTP',
            'adapter_type' => 'smtp',
            'sent_count' => 96,
            'failed_count' => 4,
            'retry_count' => 2,
            'avg_latency_ms' => 800,
        ]];

        $html = $this->render(true);

        self::assertStringContainsString('Provider reliability', $html);
        self::assertStringContainsString('Pro analytics', $html);
        self::assertStringContainsString('do not guarantee inbox placement or a provider SLA', $html);
        self::assertStringContainsString('analytics-reliability', $html);
        self::assertStringContainsString('Established sample', $html);
        self::assertStringContainsString('"score":96', $html);
    }

    public function test_free_dashboard_does_not_render_pro_reliability_data(): void
    {
        $GLOBALS['wpdb']->dashboardActivityRowsBySince[$this->sinceDaysAgo(7)] = [
            'sent_count' => 1,
            'failed_count' => 0,
            'retry_count' => 0,
        ];

        self::assertStringNotContainsString('Provider reliability', $this->render());
    }

    private function render(bool $proAnalytics = false): string
    {
        $features = new FeatureGate(
            $proAnalytics ? [FeatureGate::ADVANCED_ANALYTICS => true] : [],
            $proAnalytics
        );
        $admin = new DashboardAdmin(
            new MetricsRepository(),
            static fn (): int => self::NOW,
            new ProviderReliabilityScorer(),
            $features
        );

        ob_start();
        try {
            $admin->render();

            return (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();

            throw $throwable;
        }
    }

    private function sinceDaysAgo(int $days): string
    {
        return gmdate('Y-m-d H:i:s', self::NOW - ($days * DAY_IN_SECONDS));
    }
}
