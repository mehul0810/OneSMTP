<?php

declare(strict_types=1);

namespace OneSMTP\Summary;

use OneSMTP\Repository\MetricsRepository;
use OneSMTP\Security\Redactor;

final class WeeklySummaryMailer
{
    public const HOOK = 'onesmtp_weekly_delivery_summary';
    private const RECURRENCE = 'onesmtp_weekly';
    private const WEEK_SECONDS = 604800;
    private const MAX_PROVIDER_ROWS = 10;

    public function __construct(
        private ?WeeklySummarySettingsRepository $settings = null,
        private ?MetricsRepository $metrics = null,
        private ?Redactor $redactor = null
    ) {
        $this->settings = $settings ?? new WeeklySummarySettingsRepository();
        $this->metrics = $metrics ?? new MetricsRepository();
        $this->redactor = $redactor ?? new Redactor();
    }

    public function registerHooks(): void
    {
        add_filter('cron_schedules', [$this, 'registerSchedule']);
        add_action('init', [$this, 'syncSchedule']);
        add_action(self::HOOK, [$this, 'sendScheduledSummary']);
    }

    public function registerSchedule(array $schedules): array
    {
        $schedules[self::RECURRENCE] = [
            'interval' => self::WEEK_SECONDS,
            'display' => __('Once Weekly', 'onesmtp'),
        ];

        return $schedules;
    }

    public function syncSchedule(): void
    {
        $settings = $this->settings->get();

        if (! $settings->isEnabled()) {
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(self::HOOK);
            }

            return;
        }

        if (wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        wp_schedule_event(time() + self::WEEK_SECONDS, self::RECURRENCE, self::HOOK);
    }

    public function sendScheduledSummary(): bool
    {
        $settings = $this->settings->get();
        if (! $settings->isEnabled()) {
            return false;
        }

        $since = gmdate('Y-m-d H:i:s', time() - self::WEEK_SECONDS);
        $summary = $this->buildSummary($since);

        return wp_mail(
            $settings->getEmailRecipients(),
            __('Aculect Mail weekly delivery summary', 'onesmtp'),
            $this->renderPlainText($summary),
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function buildSummary(string $since): array
    {
        $activity = $this->metrics->getActivityWindowSummary($since);
        $pending = $this->metrics->getPendingSummary();
        $allProviders = $this->metrics->getProviderBreakdown($since);
        $providers = array_slice($allProviders, 0, self::MAX_PROVIDER_ROWS);

        return [
            'since' => $since,
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'site_name' => $this->redactor->redactText((string) get_bloginfo('name'), 120),
            'activity' => $activity,
            'pending' => $pending,
            'providers' => array_map([$this, 'safeProviderRow'], $providers),
            'provider_rows_truncated' => count($allProviders) > self::MAX_PROVIDER_ROWS,
        ];
    }

    /**
     * @param array<string,mixed> $summary
     */
    public function renderPlainText(array $summary): string
    {
        $activity = is_array($summary['activity'] ?? null) ? $summary['activity'] : [];
        $pending = is_array($summary['pending'] ?? null) ? $summary['pending'] : [];
        $providers = is_array($summary['providers'] ?? null) ? $summary['providers'] : [];

        $lines = [
            'Aculect Mail weekly delivery summary',
            'Site: ' . (string) ($summary['site_name'] ?? ''),
            'Window start (UTC): ' . (string) ($summary['since'] ?? ''),
            'Generated (UTC): ' . (string) ($summary['generated_at'] ?? ''),
            '',
            'Activity',
            '- Sent attempts: ' . (int) ($activity['sent_count'] ?? 0),
            '- Failed attempts: ' . (int) ($activity['failed_count'] ?? 0),
            '- Retry attempts: ' . (int) ($activity['retry_count'] ?? 0),
            '- Provider failovers: ' . (int) ($activity['failover_count'] ?? 0),
            '',
            'Pending queue',
            '- Queued: ' . (int) ($pending['queued_count'] ?? 0),
            '- Retry scheduled: ' . (int) ($pending['retry_scheduled_count'] ?? 0),
            '- Retrying: ' . (int) ($pending['retrying_count'] ?? 0),
            '- Total pending: ' . (int) ($pending['total_pending_count'] ?? 0),
            '',
            'Provider breakdown',
        ];

        if ($providers === []) {
            $lines[] = '- No provider activity was logged in this window.';
        } else {
            foreach ($providers as $provider) {
                if (! is_array($provider)) {
                    continue;
                }

                $lines[] = sprintf(
                    '- %s (%s): sent %d, failed %d, retries %d, failovers %d',
                    (string) ($provider['provider_name'] ?? 'Unknown provider'),
                    (string) ($provider['adapter_type'] ?? 'unknown'),
                    (int) ($provider['sent_count'] ?? 0),
                    (int) ($provider['failed_count'] ?? 0),
                    (int) ($provider['retry_count'] ?? 0),
                    (int) ($provider['failover_count'] ?? 0)
                );
            }

            if (! empty($summary['provider_rows_truncated'])) {
                $lines[] = '- Additional provider rows were omitted to keep this summary bounded.';
            }
        }

        if (
            (int) ($activity['sent_count'] ?? 0) === 0
            && (int) ($activity['failed_count'] ?? 0) === 0
            && (int) ($activity['retry_count'] ?? 0) === 0
            && (int) ($activity['failover_count'] ?? 0) === 0
        ) {
            $lines[] = '';
            $lines[] = 'No delivery activity was logged in the last 7 days.';
        }

        $lines[] = '';
        $lines[] = 'Privacy: this summary includes aggregate counts and safe provider labels only. It excludes message bodies, raw recipients, headers, secrets, attachment paths, and diagnostic payload JSON.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function safeProviderRow(array $row): array
    {
        return [
            'provider_id' => max(0, (int) ($row['provider_id'] ?? 0)),
            'provider_name' => $this->redactor->redactText(sanitize_text_field((string) ($row['provider_name'] ?? 'Unknown provider')), 120),
            'adapter_type' => sanitize_key((string) ($row['adapter_type'] ?? 'unknown')),
            'sent_count' => max(0, (int) ($row['sent_count'] ?? 0)),
            'failed_count' => max(0, (int) ($row['failed_count'] ?? 0)),
            'retry_count' => max(0, (int) ($row['retry_count'] ?? 0)),
            'failover_count' => max(0, (int) ($row['failover_count'] ?? 0)),
            'total_activity' => max(0, (int) ($row['total_activity'] ?? 0)),
        ];
    }
}
