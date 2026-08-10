<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Analytics\ProviderReliabilityScorer;
use OneSMTP\Analytics\SubjectGroupFormatter;
use OneSMTP\Core\Capabilities;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\MetricsRepository;

final class DashboardAdmin
{
    private const WINDOW_LAST_24_HOURS = 'last_24_hours';
    private const WINDOW_LAST_7_DAYS = 'last_7_days';
    private const WINDOW_LAST_30_DAYS = 'last_30_days';
    private const PROVIDER_WINDOW_KEY = self::WINDOW_LAST_7_DAYS;
    private const PROVIDER_NAME_LIMIT = 80;

    private MetricsRepository $metrics;
    private ProviderReliabilityScorer $reliability;
    private SubjectGroupFormatter $subjectGroups;
    private FeatureGate $features;

    /** @var callable():int */
    private $nowProvider;

    /**
     * @param callable():int|null $nowProvider
     */
    public function __construct(
        ?MetricsRepository $metrics = null,
        ?callable $nowProvider = null,
        ?ProviderReliabilityScorer $reliability = null,
        ?FeatureGate $features = null,
        ?SubjectGroupFormatter $subjectGroups = null
    )
    {
        $this->metrics = $metrics ?? new MetricsRepository();
        $this->nowProvider = $nowProvider ?? static fn (): int => time();
        $this->reliability = $reliability ?? new ProviderReliabilityScorer();
        $this->features = $features ?? new FeatureGate();
        $this->subjectGroups = $subjectGroups ?? new SubjectGroupFormatter();
    }

    public function render(): void
    {
        if (! Capabilities::canViewLogs()) {
            wp_die(
                esc_html__('You do not have permission to view Aculect Mail dashboard metrics.', 'onesmtp'),
                esc_html__('Aculect Mail access denied', 'onesmtp'),
                ['response' => 403]
            );
        }

        $windows = $this->windowSummaries();
        $pending = $this->metrics->getPendingSummary();
        $selectedWindow = isset($_GET['onesmtp_analytics_window']) ? sanitize_key(wp_unslash((string) $_GET['onesmtp_analytics_window'])) : self::PROVIDER_WINDOW_KEY;
        $providerWindow = $windows[$selectedWindow] ?? $windows[self::PROVIDER_WINDOW_KEY];
        $providers = $this->metrics->getProviderBreakdown((string) $providerWindow['since']);
        $empty = $this->isEmpty($windows, $pending, $providers);

        echo '<div class="onesmtp-analytics-toolbar"><p>' . esc_html__('Understand email delivery performance at a glance.', 'onesmtp') . '</p><form method="get"><input type="hidden" name="page" value="onesmtp"><input type="hidden" name="tab" value="onesmtp-analytics"><label class="screen-reader-text" for="onesmtp-analytics-window">' . esc_html__('Analytics date range', 'onesmtp') . '</label><select id="onesmtp-analytics-window" name="onesmtp_analytics_window" onchange="this.form.submit()">';
        foreach ([self::WINDOW_LAST_7_DAYS => __('Last 7 days', 'onesmtp'), self::WINDOW_LAST_30_DAYS => __('Last 30 days', 'onesmtp')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . ($selectedWindow === $value ? ' selected="selected"' : '') . '>' . esc_html($label) . '</option>';
        }
        echo '</select></form></div>';
        if ($empty) {
            $this->renderEmptyAnalytics();
            return;
        }
        $this->renderSummaryCards($windows[self::PROVIDER_WINDOW_KEY], $pending);

        if ($this->features->isEnabled(FeatureGate::ADVANCED_ANALYTICS)) {
            $this->renderReliabilityDashboard($providers, (string) $providerWindow['label']);
            $advancedReport = $this->metrics->getAdvancedReport(
                (string) $providerWindow['since'],
                (string) $providerWindow['until'],
                20
            );
            $this->renderAdvancedReports($advancedReport, (string) $providerWindow['label']);
        }

        $this->renderWindowTable($windows);
        echo '<details class="onesmtp-analytics-details"><summary>' . esc_html__('Queue detail', 'onesmtp') . '</summary>';
        $this->renderPendingTable($pending);
        echo '</details>';
        $this->renderProviderTable($providers, (string) $providerWindow['label']);
    }

    /**
     * @param array<int,array{provider_id:int,provider_name:string,adapter_type:string,sent_count:int,failed_count:int,retry_count:int,avg_latency_ms:?int,failover_count:int,switch_out_count:int,total_activity:int}> $providers
     */
    private function renderReliabilityDashboard(array $providers, string $windowLabel): void
    {
        echo '<section class="onesmtp-reliability-panel" aria-labelledby="onesmtp-reliability-heading">';
        echo '<div class="onesmtp-reliability-heading"><div><h3 id="onesmtp-reliability-heading">' . esc_html__('Provider reliability', 'onesmtp') . '</h3><p>' . esc_html(sprintf(
            /* translators: %s: selected analytics date window. */
            __('Operational score based on recorded attempts for %s.', 'onesmtp'),
            $windowLabel
        )) . '</p></div><span class="onesmtp-status-pill is-ready">' . esc_html__('Pro analytics', 'onesmtp') . '</span></div>';
        echo '<p class="onesmtp-reliability-disclaimer">' . esc_html__('Scores combine successful and failed attempts, retries, provider switches, and average response latency. They describe Aculect Mail history and do not guarantee inbox placement or a provider SLA.', 'onesmtp') . '</p>';

        $rows = [];
        foreach ($providers as $provider) {
            if ((int) ($provider['provider_id'] ?? 0) <= 0) {
                continue;
            }

            $score = $this->reliability->score($provider);
            if ($score['attempt_count'] === 0) {
                continue;
            }

            $rows[] = [
                'id' => (int) $provider['provider_id'],
                'provider' => (string) $provider['provider_name'],
                'score' => $score['score'],
                'confidence' => $score['confidence'] === ProviderReliabilityScorer::CONFIDENCE_ESTABLISHED
                    ? __('Established sample', 'onesmtp')
                    : __('Limited sample', 'onesmtp'),
                'successRate' => $this->formatPercent($score['success_rate']),
                'latency' => $score['avg_latency_ms'] !== null
                    ? sprintf(
                        /* translators: %d: average provider response time in milliseconds. */
                        __('%d ms', 'onesmtp'),
                        $score['avg_latency_ms']
                    )
                    : __('No latency data', 'onesmtp'),
                'switchRate' => $this->formatPercent($score['switch_rate']),
                'attempts' => $score['attempt_count'],
            ];
        }

        if ($rows === []) {
            echo '<p class="onesmtp-reliability-empty">' . esc_html__('Reliability scoring begins after a provider records a delivery attempt.', 'onesmtp') . '</p></section>';
            return;
        }

        $payload = ['data' => $rows, 'fields' => [
            ['id' => 'provider', 'type' => 'text', 'label' => __('Provider', 'onesmtp'), 'enableHiding' => false],
            ['id' => 'score', 'type' => 'integer', 'label' => __('Reliability score', 'onesmtp')],
            ['id' => 'confidence', 'type' => 'text', 'label' => __('Evidence', 'onesmtp')],
            ['id' => 'successRate', 'type' => 'text', 'label' => __('Success rate', 'onesmtp')],
            ['id' => 'latency', 'type' => 'text', 'label' => __('Average latency', 'onesmtp')],
            ['id' => 'switchRate', 'type' => 'text', 'label' => __('Switch-away rate', 'onesmtp')],
            ['id' => 'attempts', 'type' => 'integer', 'label' => __('Attempts', 'onesmtp')],
        ]];
        echo '<div class="onesmtp-dataviews-mount" data-onesmtp-dataviews="analytics-reliability"></div>';
        echo '<script type="application/json" data-onesmtp-dataviews-config="analytics-reliability">' . wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        echo '<details class="onesmtp-legacy-list"><summary>' . esc_html__('Reliability score detail', 'onesmtp') . '</summary><table class="widefat striped onesmtp-reliability-table"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Provider', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Score', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Evidence', 'onesmtp') . '</th>';
        foreach ([__('Success', 'onesmtp'), __('Latency', 'onesmtp'), __('Switched away', 'onesmtp'), __('Attempts', 'onesmtp')] as $heading) {
            echo '<th scope="col" class="onesmtp-reliability-secondary">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row">' . esc_html((string) $row['provider']) . '</th><td>' . esc_html((string) $row['score']) . '</td><td>' . esc_html((string) $row['confidence']) . '</td><td class="onesmtp-reliability-secondary">' . esc_html((string) $row['successRate']) . '</td><td class="onesmtp-reliability-secondary">' . esc_html((string) $row['latency']) . '</td><td class="onesmtp-reliability-secondary">' . esc_html((string) $row['switchRate']) . '</td><td class="onesmtp-reliability-secondary">' . esc_html((string) $row['attempts']) . '</td></tr>';
        }
        echo '</tbody></table></details></section>';
    }

    /**
     * Render bounded Pro report slices sourced from the message and attempt
     * tables. The output intentionally excludes payload_json and event context.
     *
     * @param array{
     *     error:bool,
     *     providers:array<int,array<string,mixed>>,
     *     statuses:array<int,array{status:string,count:int}>,
     *     subjects:array<int,array{subject:string,count:int}>,
     *     trend:array<int,array{period:string,status:string,count:int}>,
     *     failure_categories:array<int,array{category:string,count:int,last_seen_at:?string}>
     * } $report
     */
    private function renderAdvancedReports(array $report, string $windowLabel): void
    {
        echo '<section class="onesmtp-advanced-reports" aria-labelledby="onesmtp-advanced-reports-heading">';
        echo '<div class="onesmtp-reliability-heading"><div><h3 id="onesmtp-advanced-reports-heading">' . esc_html__('Advanced reports', 'onesmtp') . '</h3><p>' . esc_html(sprintf(
            /* translators: %s: selected analytics date window. */
            __('Bounded report slices for %s, matched to stored delivery logs.', 'onesmtp'),
            $windowLabel
        )) . '</p></div><span class="onesmtp-status-pill is-ready">' . esc_html__('Pro analytics', 'onesmtp') . '</span></div>';
        echo '<p class="onesmtp-reliability-disclaimer">' . esc_html__('Reports use aggregate message and attempt records only. Message bodies, recipients, credentials, provider payloads, and raw event context are never included.', 'onesmtp') . '</p>';

        if ((bool) ($report['error'] ?? false)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Advanced reports are temporarily unavailable. Review the Activity logs and try again later.', 'onesmtp') . '</p></div></section>';

            return;
        }

        $providers = $this->advancedProviderRows($report['providers'] ?? []);
        $subjects = $this->advancedSubjectRows($report['subjects'] ?? []);
        $statuses = $report['statuses'] ?? [];
        $trend = $report['trend'] ?? [];
        $failures = $report['failure_categories'] ?? [];
        if ($providers === [] && $subjects === [] && $statuses === [] && $trend === [] && $failures === []) {
            echo '<p class="onesmtp-advanced-reports-empty">' . esc_html__('No report data is available for this window yet.', 'onesmtp') . '</p></section>';

            return;
        }

        echo '<div class="onesmtp-advanced-reports-grid">';
        $this->renderAdvancedProviderTable($providers);
        $this->renderAdvancedStatusTable($statuses);
        $this->renderAdvancedSubjectTable($subjects);
        $this->renderAdvancedTrendTable($trend);
        $this->renderAdvancedFailureTable($failures);
        echo '</div></section>';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{provider:string,score:int,attempts:int,sent:int,failed:int,latency:string}>
     */
    private function advancedProviderRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $provider) {
            $score = $this->reliability->score($provider);
            $attempts = max(0, (int) ($provider['attempt_count'] ?? $score['attempt_count']));
            if ($attempts === 0) {
                continue;
            }

            $result[] = [
                'provider' => (string) ($provider['provider_name'] ?? __('Unknown provider', 'onesmtp')),
                'score' => $score['score'],
                'attempts' => $attempts,
                'sent' => max(0, (int) ($provider['sent_count'] ?? 0)),
                'failed' => max(0, (int) ($provider['failed_count'] ?? 0)),
                'latency' => $score['avg_latency_ms'] !== null
                    ? sprintf(
                        /* translators: %d: average provider response time in milliseconds. */
                        __('%d ms', 'onesmtp'),
                        $score['avg_latency_ms']
                    )
                    : __('No latency data', 'onesmtp'),
            ];
        }

        return $result;
    }

    /**
     * @param array<int,array{subject:string,count:int}> $rows
     * @return array<int,array{subject:string,count:int}>
     */
    private function advancedSubjectRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $subject = (string) ($row['subject'] ?? '');
            $key = $this->subjectGroups->key($subject);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'subject' => $this->subjectGroups->label($subject),
                    'count' => 0,
                ];
            }

            $groups[$key]['count'] += max(0, (int) ($row['count'] ?? 0));
        }

        $groups = array_values($groups);
        usort(
            $groups,
            static fn (array $a, array $b): int => ((int) $b['count'] <=> (int) $a['count']) ?: strcmp((string) $a['subject'], (string) $b['subject'])
        );

        return $groups;
    }

    /** @param array<int,array{provider:string,score:int,attempts:int,sent:int,failed:int,latency:string}> $rows */
    private function renderAdvancedProviderTable(array $rows): void
    {
        echo '<div class="onesmtp-advanced-report-card"><h4>' . esc_html__('Provider report', 'onesmtp') . '</h4>';
        if ($rows === []) {
            echo '<p>' . esc_html__('No provider attempts were recorded in this window.', 'onesmtp') . '</p></div>';

            return;
        }

        echo '<div class="onesmtp-advanced-report-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Provider', 'onesmtp') . '</th><th scope="col">' . esc_html__('Score', 'onesmtp') . '</th><th scope="col">' . esc_html__('Attempts', 'onesmtp') . '</th><th scope="col">' . esc_html__('Sent', 'onesmtp') . '</th><th scope="col">' . esc_html__('Failed', 'onesmtp') . '</th><th scope="col">' . esc_html__('Latency', 'onesmtp') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row">' . esc_html($row['provider']) . '</th><td>' . esc_html((string) $row['score']) . '</td><td>' . esc_html((string) $row['attempts']) . '</td><td>' . esc_html((string) $row['sent']) . '</td><td>' . esc_html((string) $row['failed']) . '</td><td>' . esc_html($row['latency']) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /** @param array<int,array{status:string,count:int}> $rows */
    private function renderAdvancedStatusTable(array $rows): void
    {
        echo '<div class="onesmtp-advanced-report-card"><h4>' . esc_html__('Status distribution', 'onesmtp') . '</h4>';
        if ($rows === []) {
            echo '<p>' . esc_html__('No message statuses were recorded in this window.', 'onesmtp') . '</p></div>';

            return;
        }

        echo '<div class="onesmtp-advanced-report-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Status', 'onesmtp') . '</th><th scope="col">' . esc_html__('Messages', 'onesmtp') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row">' . esc_html($this->formatStatus($row['status'])) . '</th><td>' . esc_html($this->formatCount((int) $row['count'])) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /** @param array<int,array{subject:string,count:int}> $rows */
    private function renderAdvancedSubjectTable(array $rows): void
    {
        echo '<div class="onesmtp-advanced-report-card"><h4>' . esc_html__('Subject groups', 'onesmtp') . '</h4><p class="description">' . esc_html__('Labels use only the stored subject, with redaction and an 80-character display bound.', 'onesmtp') . '</p>';
        if ($rows === []) {
            echo '<p>' . esc_html__('No subject groups were recorded in this window.', 'onesmtp') . '</p></div>';

            return;
        }

        echo '<div class="onesmtp-advanced-report-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Subject group', 'onesmtp') . '</th><th scope="col">' . esc_html__('Messages', 'onesmtp') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row" style="max-width:32em;white-space:normal;overflow-wrap:anywhere;">' . esc_html($row['subject']) . '</th><td>' . esc_html($this->formatCount((int) $row['count'])) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /** @param array<int,array{period:string,status:string,count:int}> $rows */
    private function renderAdvancedTrendTable(array $rows): void
    {
        echo '<div class="onesmtp-advanced-report-card"><h4>' . esc_html__('Delivery trend', 'onesmtp') . '</h4>';
        if ($rows === []) {
            echo '<p>' . esc_html__('No daily message trend is available for this window.', 'onesmtp') . '</p></div>';

            return;
        }

        echo '<div class="onesmtp-advanced-report-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Day (UTC)', 'onesmtp') . '</th><th scope="col">' . esc_html__('Status', 'onesmtp') . '</th><th scope="col">' . esc_html__('Messages', 'onesmtp') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row">' . esc_html($row['period']) . '</th><td>' . esc_html($this->formatStatus($row['status'])) . '</td><td>' . esc_html($this->formatCount((int) $row['count'])) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /** @param array<int,array{category:string,count:int,last_seen_at:?string}> $rows */
    private function renderAdvancedFailureTable(array $rows): void
    {
        echo '<div class="onesmtp-advanced-report-card"><h4>' . esc_html__('Failure categories', 'onesmtp') . '</h4>';
        if ($rows === []) {
            echo '<p>' . esc_html__('No failed attempts were recorded in this window.', 'onesmtp') . '</p></div>';

            return;
        }

        echo '<div class="onesmtp-advanced-report-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Category', 'onesmtp') . '</th><th scope="col">' . esc_html__('Failed attempts', 'onesmtp') . '</th><th scope="col">' . esc_html__('Last seen (UTC)', 'onesmtp') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $category = sanitize_key((string) $row['category']);
            echo '<tr><th scope="row">' . esc_html($category !== '' ? str_replace('_', ' ', $category) : __('Uncategorized', 'onesmtp')) . '</th><td>' . esc_html($this->formatCount((int) $row['count'])) . '</td><td>' . esc_html((string) ($row['last_seen_at'] ?? __('Unavailable', 'onesmtp'))) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private function renderEmptyAnalytics(): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Heroicons renders an SVG selected from a private allowlist and escapes its only dynamic attribute.
        echo '<section class="onesmtp-analytics-empty">' . Heroicons::render('squares') . '<h3>' . esc_html__('No delivery data yet', 'onesmtp') . '</h3><p>' . esc_html__('Connect a provider and send your first message. Delivery trends and provider comparisons will appear here automatically.', 'onesmtp') . '</p><a class="button button-primary" href="' . esc_url(admin_url('options-general.php?page=onesmtp&tab=onesmtp-providers#onesmtp-providers')) . '">' . esc_html__('Connect a provider', 'onesmtp') . '</a></section>';
    }

    /** @param array<string,int|string> $window @param array<string,int> $pending */
    private function renderSummaryCards(array $window, array $pending): void
    {
        $sent = (int) ($window['sent_count'] ?? 0);
        $failed = (int) ($window['failed_count'] ?? 0);
        $successRate = $sent > 0 ? (int) round((($sent - $failed) / $sent) * 100) : 0;
        $cards = [
            [__('Delivery success', 'onesmtp'), $sent > 0 ? $successRate . '%' : __('No data', 'onesmtp'), __('Last 7 days', 'onesmtp')],
            [__('Sent attempts', 'onesmtp'), $this->formatCount($sent), __('Last 7 days', 'onesmtp')],
            [__('Failovers', 'onesmtp'), $this->formatCount((int) ($window['failover_count'] ?? 0)), __('Last 7 days', 'onesmtp')],
            [__('Pending messages', 'onesmtp'), $this->formatCount((int) ($pending['total_pending_count'] ?? 0)), __('Current queue', 'onesmtp')],
        ];

        echo '<div class="onesmtp-analytics-summary" aria-label="' . esc_attr__('Analytics summary', 'onesmtp') . '">';
        foreach ($cards as [$label, $value, $context]) {
            echo '<div class="onesmtp-analytics-card"><span class="onesmtp-analytics-card-label">' . esc_html($label) . '</span><strong>' . esc_html((string) $value) . '</strong><span class="onesmtp-analytics-card-context">' . esc_html($context) . '</span></div>';
        }
        echo '</div>';
    }

    /**
     * @return array<string,array{label:string,since:string,until:string,sent_count:int,failed_count:int,retry_count:int,failover_count:int}>
     */
    private function windowSummaries(): array
    {
        $now = max(0, (int) ($this->nowProvider)());
        $windows = [
            self::WINDOW_LAST_24_HOURS => [
                'label' => __('Last 24 hours', 'onesmtp'),
                'since' => gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS),
                'until' => gmdate('Y-m-d H:i:s', $now),
            ],
            self::WINDOW_LAST_7_DAYS => [
                'label' => __('Last 7 days', 'onesmtp'),
                'since' => gmdate('Y-m-d H:i:s', $now - (7 * DAY_IN_SECONDS)),
                'until' => gmdate('Y-m-d H:i:s', $now),
            ],
            self::WINDOW_LAST_30_DAYS => [
                'label' => __('Last 30 days', 'onesmtp'),
                'since' => gmdate('Y-m-d H:i:s', $now - (30 * DAY_IN_SECONDS)),
                'until' => gmdate('Y-m-d H:i:s', $now),
            ],
        ];

        foreach ($windows as $key => $window) {
            $windows[$key] += $this->metrics->getActivityWindowSummary((string) $window['since']);
        }

        return $windows;
    }

    /**
     * @param array<string,array{label:string,since:string,until:string,sent_count:int,failed_count:int,retry_count:int,failover_count:int}> $windows
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
     * @param array<int,array{provider_id:int,provider_name:string,adapter_type:string,sent_count:int,failed_count:int,retry_count:int,avg_latency_ms:?int,failover_count:int,switch_out_count:int,total_activity:int}> $providers
     */
    private function renderProviderTable(array $providers, string $windowLabel): void
    {
        $this->renderProviderDataViews($providers);
        echo '<details class="onesmtp-legacy-list"><summary>' . esc_html__('Provider activity detail', 'onesmtp') . '</summary>';
        echo '<h3>' . esc_html__('Provider activity', 'onesmtp') . '</h3>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: activity window label. */
            __('Provider breakdown for %s.', 'onesmtp'),
            $windowLabel
        )) . '</p>';

        if ($providers === []) {
            echo '<p>' . esc_html__('No provider-level activity has been recorded for this window.', 'onesmtp') . '</p>';
            echo '</details>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Provider', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Type', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Sent', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Failed', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Retries', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Switched to', 'onesmtp') . '</th>';
        echo '<th scope="col">' . esc_html__('Switched away', 'onesmtp') . '</th>';
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
            echo '<td>' . esc_html($this->formatCount((int) ($provider['switch_out_count'] ?? 0))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></details>';
    }

    /** @param array<int,array{provider_id:int,provider_name:string,adapter_type:string,sent_count:int,failed_count:int,retry_count:int,avg_latency_ms:?int,failover_count:int,switch_out_count:int,total_activity:int}> $providers */
    private function renderProviderDataViews(array $providers): void
    {
        $data = array_map(static function (array $provider): array {
            return ['id' => (int) $provider['provider_id'], 'provider' => (string) $provider['provider_name'], 'type' => (string) $provider['adapter_type'], 'sent' => (int) $provider['sent_count'], 'failed' => (int) $provider['failed_count'], 'retries' => (int) $provider['retry_count'], 'failovers' => (int) $provider['failover_count'], 'switchedAway' => (int) $provider['switch_out_count']];
        }, $providers);
        $payload = ['data' => $data, 'fields' => [
            ['id' => 'provider', 'type' => 'text', 'label' => __('Provider', 'onesmtp'), 'enableHiding' => false],
            ['id' => 'type', 'type' => 'text', 'label' => __('Type', 'onesmtp')],
            ['id' => 'sent', 'type' => 'integer', 'label' => __('Sent', 'onesmtp')],
            ['id' => 'failed', 'type' => 'integer', 'label' => __('Failed', 'onesmtp')],
            ['id' => 'retries', 'type' => 'integer', 'label' => __('Retries', 'onesmtp')],
            ['id' => 'failovers', 'type' => 'integer', 'label' => __('Failovers', 'onesmtp')],
            ['id' => 'switchedAway', 'type' => 'integer', 'label' => __('Switched away', 'onesmtp')],
        ]];
        echo '<div class="onesmtp-dataviews-mount" data-onesmtp-dataviews="analytics-providers"></div>';
        echo '<script type="application/json" data-onesmtp-dataviews-config="analytics-providers">' . wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
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

    private function formatPercent(float $value): string
    {
        return number_format(max(0.0, min(100.0, $value)), 1) . '%';
    }

    private function formatProviderType(string $type): string
    {
        $type = sanitize_key($type);

        return $type !== '' ? str_replace('_', ' ', $type) : __('unknown', 'onesmtp');
    }

    private function formatStatus(string $status): string
    {
        $status = sanitize_key($status);

        if ($status === 'simulated') {
            return __('Simulated', 'onesmtp');
        }

        return $status !== '' ? str_replace('_', ' ', $status) : __('Unknown', 'onesmtp');
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
