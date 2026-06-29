<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Core\Capabilities;
use OneSMTP\Repository\MetricsRepository;

final class DashboardAdmin
{
    private const WINDOW_LAST_24_HOURS = 'last_24_hours';
    private const WINDOW_LAST_7_DAYS = 'last_7_days';
    private const PROVIDER_WINDOW_KEY = self::WINDOW_LAST_7_DAYS;
    private const PROVIDER_NAME_LIMIT = 80;

    private MetricsRepository $metrics;

    /** @var callable():int */
    private $nowProvider;

    /**
     * @param callable():int|null $nowProvider
     */
    public function __construct(?MetricsRepository $metrics = null, ?callable $nowProvider = null)
    {
        $this->metrics = $metrics ?? new MetricsRepository();
        $this->nowProvider = $nowProvider ?? static fn (): int => time();
    }

    public function render(): void
    {
        if (! Capabilities::canViewLogs()) {
            wp_die(
                esc_html__('You do not have permission to view OneSMTP dashboard metrics.', 'onesmtp'),
                esc_html__('OneSMTP access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $windows = $this->windowSummaries();
        $pending = $this->metrics->getPendingSummary();
        $providerWindow = $windows[self::PROVIDER_WINDOW_KEY];
        $providers = $this->metrics->getProviderBreakdown((string) $providerWindow['since']);

        echo '<p>' . esc_html__('Review aggregate delivery health without exposing recipients, subjects, message bodies, raw headers, stored payloads, credentials, or provider secrets.', 'onesmtp') . '</p>';

        if ($this->isEmpty($windows, $pending, $providers)) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('No delivery activity has been recorded yet. Metrics will appear after OneSMTP records sends, retries, failures, or failover events.', 'onesmtp') . '</p></div>';
        }

        $this->renderWindowTable($windows);
        $this->renderPendingTable($pending);
        $this->renderProviderTable($providers, (string) $providerWindow['label']);
    }

    /**
     * @return array<string,array{label:string,since:string,sent_count:int,failed_count:int,retry_count:int,failover_count:int}>
     */
    private function windowSummaries(): array
    {
        $now = max(0, (int) ($this->nowProvider)());
        $windows = [
            self::WINDOW_LAST_24_HOURS => [
                'label' => __('Last 24 hours', 'onesmtp'),
                'since' => gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS),
            ],
            self::WINDOW_LAST_7_DAYS => [
                'label' => __('Last 7 days', 'onesmtp'),
                'since' => gmdate('Y-m-d H:i:s', $now - (7 * DAY_IN_SECONDS)),
            ],
        ];

        foreach ($windows as $key => $window) {
            $windows[$key] += $this->metrics->getActivityWindowSummary((string) $window['since']);
        }

        return $windows;
    }

    /**
     * @param array<string,array{label:string,since:string,sent_count:int,failed_count:int,retry_count:int,failover_count:int}> $windows
     */
    private function renderWindowTable(array $windows): void
    {
        echo '<h3>' . esc_html__('Delivery activity', 'onesmtp') . '</h3>';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Window', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Sent attempts', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Failed attempts', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Retry attempts', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Failovers', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($windows as $window) {
            echo '<tr>';
            echo '<th scope="row">' . esc_html((string) $window['label']) . '<br><span class="description">' . esc_html(sprintf(
                /* translators: %s: UTC datetime. */
                __('Since %s UTC', 'onesmtp'),
                (string) $window['since']
            )) . '</span></th>';
            echo '<td>' . esc_html($this->formatCount((int) $window['sent_count'])) . '</td>';
            echo '<td>' . esc_html($this->formatCount((int) $window['failed_count'])) . '</td>';
            echo '<td>' . esc_html($this->formatCount((int) $window['retry_count'])) . '</td>';
            echo '<td>' . esc_html($this->formatCount((int) $window['failover_count'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array{queued_count:int,retry_scheduled_count:int,retrying_count:int,total_pending_count:int} $pending
     */
    private function renderPendingTable(array $pending): void
    {
        echo '<h3>' . esc_html__('Pending messages', 'onesmtp') . '</h3>';

        if ((int) $pending['total_pending_count'] === 0) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('No messages are currently queued, scheduled for retry, or retrying.', 'onesmtp') . '</p></div>';
        }

        echo '<table class="widefat striped">';
        echo '<tbody>';
        $this->renderMetricRow(__('Total pending', 'onesmtp'), (int) $pending['total_pending_count']);
        $this->renderMetricRow(__('Queued', 'onesmtp'), (int) $pending['queued_count']);
        $this->renderMetricRow(__('Scheduled retries', 'onesmtp'), (int) $pending['retry_scheduled_count']);
        $this->renderMetricRow(__('Running retries', 'onesmtp'), (int) $pending['retrying_count']);
        echo '</tbody>';
        echo '</table>';
    }

    /**
     * @param array<int,array{provider_id:int,provider_name:string,adapter_type:string,sent_count:int,failed_count:int,retry_count:int,failover_count:int,total_activity:int}> $providers
     */
    private function renderProviderTable(array $providers, string $windowLabel): void
    {
        echo '<h3>' . esc_html__('Provider activity', 'onesmtp') . '</h3>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: activity window label. */
            __('Provider breakdown for %s.', 'onesmtp'),
            $windowLabel
        )) . '</p>';

        if ($providers === []) {
            echo '<p>' . esc_html__('No provider-level activity has been recorded for this window.', 'onesmtp') . '</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Provider', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Type', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Sent', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Failed', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Retries', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Failovers', 'onesmtp') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($providers as $provider) {
            $fullName = (string) $provider['provider_name'];
            echo '<tr>';
            echo '<th scope="row" title="' . esc_attr($fullName) . '">' . esc_html($this->shorten($fullName)) . '</th>';
            echo '<td>' . esc_html($this->formatProviderType((string) $provider['adapter_type'])) . '</td>';
            echo '<td>' . esc_html($this->formatCount((int) $provider['sent_count'])) . '</td>';
            echo '<td>' . esc_html($this->formatCount((int) $provider['failed_count'])) . '</td>';
            echo '<td>' . esc_html($this->formatCount((int) $provider['retry_count'])) . '</td>';
            echo '<td>' . esc_html($this->formatCount((int) $provider['failover_count'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function renderMetricRow(string $label, int $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($this->formatCount($value)) . '</td></tr>';
    }

    /**
     * @param array<string,array{sent_count:int,failed_count:int,retry_count:int,failover_count:int}> $windows
     * @param array{total_pending_count:int} $pending
     * @param array<int,array{total_activity:int}> $providers
     */
    private function isEmpty(array $windows, array $pending, array $providers): bool
    {
        foreach ($windows as $window) {
            if ((int) $window['sent_count'] > 0 || (int) $window['failed_count'] > 0 || (int) $window['retry_count'] > 0 || (int) $window['failover_count'] > 0) {
                return false;
            }
        }

        if ((int) $pending['total_pending_count'] > 0) {
            return false;
        }

        foreach ($providers as $provider) {
            if ((int) $provider['total_activity'] > 0) {
                return false;
            }
        }

        return true;
    }

    private function formatCount(int $value): string
    {
        return number_format(max(0, $value));
    }

    private function formatProviderType(string $type): string
    {
        $type = sanitize_key($type);

        return $type !== '' ? str_replace('_', ' ', $type) : __('unknown', 'onesmtp');
    }

    private function shorten(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return __('Unknown provider', 'onesmtp');
        }

        if (strlen($value) <= self::PROVIDER_NAME_LIMIT) {
            return $value;
        }

        return substr($value, 0, self::PROVIDER_NAME_LIMIT - 3) . '...';
    }
}
