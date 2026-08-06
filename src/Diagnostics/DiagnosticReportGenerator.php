<?php

declare(strict_types=1);

namespace OneSMTP\Diagnostics;

use OneSMTP\Core\RetentionPolicy;
use OneSMTP\Queue\QueueDiagnostics;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Security\Redactor;

final class DiagnosticReportGenerator
{
    private const SCHEMA_VERSION = 1;
    private const FAILURE_WINDOW_HOURS = 168;

    private ProviderRepository $providers;
    private QueueDiagnostics $queueDiagnostics;
    private AttemptRepository $attempts;
    private Redactor $redactor;
    /** @var callable():int */
    private $clock;

    /**
     * @param callable():int|null $clock
     */
    public function __construct(
        ProviderRepository $providers,
        QueueDiagnostics $queueDiagnostics,
        AttemptRepository $attempts,
        ?Redactor $redactor = null,
        ?callable $clock = null
    ) {
        $this->providers = $providers;
        $this->queueDiagnostics = $queueDiagnostics;
        $this->attempts = $attempts;
        $this->redactor = $redactor ?? new Redactor();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array<string,mixed>
     */
    public function generate(): array
    {
        $now = (int) ($this->clock)();
        $providers = $this->providers();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'environment' => $this->environmentSection(),
            'plugin' => $this->pluginSection($providers),
            'providers' => $providers,
            'queue' => $this->queue(),
            'recent_failures' => $this->recentFailures(),
            'redaction' => [
                'status' => 'applied',
                'excluded_fields' => [
                    'provider_config_values',
                    'stored_provider_configuration',
                    'raw_recipients',
                    'message_subjects',
                    'message_bodies',
                    'raw_headers',
                    'stored_message_payloads',
                    'webhook_urls',
                    'provider_error_messages',
                    'provider_message_ids',
                ],
                'redacted_patterns' => [
                    'api_key',
                    'password',
                    'secret',
                    'token',
                    'authorization_bearer',
                ],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function providers(): array
    {
        return array_map([$this, 'providerSummary'], $this->providers->getAllSafe());
    }

    /**
     * @return array<string,mixed>
     */
    public function queue(): array
    {
        return $this->queueDiagnostics->snapshot();
    }

    /**
     * @return array{window_hours:int,categories:array<int,array{category:string,count:int,last_seen_at:?string}>}
     */
    public function recentFailures(): array
    {
        $now = (int) ($this->clock)();

        return [
            'window_hours' => self::FAILURE_WINDOW_HOURS,
            'categories' => $this->attempts->getRecentFailureCategoryCounts(
                gmdate('Y-m-d H:i:s', max(0, $now - (self::FAILURE_WINDOW_HOURS * HOUR_IN_SECONDS)))
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function environmentSection(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'wordpress_version' => $this->redactor->redactText((string) get_bloginfo('version'), 40),
            'site_name' => $this->redactor->redactText((string) get_bloginfo('name'), 120),
            'multisite' => function_exists('is_multisite') ? (bool) is_multisite() : false,
            'wp_debug' => defined('WP_DEBUG') ? (bool) constant('WP_DEBUG') : false,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $providers
     * @return array<string,mixed>
     */
    private function pluginSection(array $providers): array
    {
        return [
            'version' => defined('ONESMTP_VERSION') ? (string) constant('ONESMTP_VERSION') : 'unknown',
            'provider_count' => count($providers),
            'active_provider_count' => count(
                array_filter(
                    $providers,
                    static fn (array $provider): bool => (int) ($provider['is_active'] ?? 0) === 1
                )
            ),
            'log_retention_days' => RetentionPolicy::getLogRetentionDays(),
        ];
    }

    /**
     * @param array<string,mixed> $provider
     * @return array<string,mixed>
     */
    private function providerSummary(array $provider): array
    {
        return [
            'id' => (int) ($provider['id'] ?? 0),
            'slug' => sanitize_key((string) ($provider['slug'] ?? '')),
            'name' => $this->redactor->redactText(sanitize_text_field((string) ($provider['name'] ?? '')), 120),
            'adapter_type' => sanitize_key((string) ($provider['adapter_type'] ?? '')),
            'is_active' => (int) ($provider['is_active'] ?? 0) === 1,
            'priority' => (int) ($provider['priority'] ?? 100),
            'weight' => (int) ($provider['weight'] ?? 1),
            'circuit_state' => sanitize_key((string) ($provider['circuit_state'] ?? 'closed')),
            'circuit_until' => isset($provider['circuit_until']) && (string) $provider['circuit_until'] !== ''
                ? sanitize_text_field((string) $provider['circuit_until'])
                : null,
            'config_values' => '[excluded]',
        ];
    }
}
