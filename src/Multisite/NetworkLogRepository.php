<?php

declare(strict_types=1);

namespace OneSMTP\Multisite;

use OneSMTP\Core\Capabilities;
use OneSMTP\Logging\AttachmentLogSanitizer;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Repository\ProviderRepository;

/**
 * Bounded, privacy-safe read model for network administrators.
 *
 * Queries run inside each site's blog context and return summaries only. Raw
 * payload JSON is decoded transiently and never included in the result.
 */
final class NetworkLogRepository
{
    private const MAX_SITES = 100;
    private const MAX_ROWS_PER_SITE = 100;

    public function __construct(
        private ?NetworkSettingsRepository $settings = null,
        private ?FeatureGate $featureGate = null
    ) {
        $this->featureGate = $featureGate ?? FeatureGate::fromRuntime();
        $this->settings = $settings ?? new NetworkSettingsRepository($this->featureGate);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listFiltered(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);
        $rows = $this->collect($filters);
        $offset = ($page - 1) * $perPage;

        return array_slice($rows, $offset, $perPage);
    }

    /** @param array<string,mixed> $filters */
    public function countFiltered(array $filters = []): int
    {
        return count($this->collect($filters));
    }

    /** @return array<int,int> */
    public function siteIds(): array
    {
        if ( ! $this->settings->isAvailable() || ! function_exists('get_sites')) {
            return [];
        }

        $sites = get_sites([
            'number' => self::MAX_SITES,
            'offset' => 0,
            'fields' => 'ids',
            'orderby' => 'id',
            'order' => 'ASC',
        ]);
        if ( ! is_array($sites)) {
            return [];
        }

        $ids = [];
        foreach ($sites as $site) {
            $id = is_object($site) && isset($site->blog_id) ? (int) $site->blog_id : (int) $site;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
    private function collect(array $filters): array
    {
        if ( ! $this->settings->isAvailable() || ! Capabilities::canViewNetworkLogs($this->featureGate)) {
            return [];
        }

        $filters = $this->normalizeFilters($filters);
        $siteIds = $this->siteIds();
        if ($filters['site_id'] > 0) {
            $siteIds = in_array($filters['site_id'], $siteIds, true) ? [$filters['site_id']] : [];
        }

        if ($siteIds === [] || ! function_exists('switch_to_blog')) {
            return [];
        }

        $rows = [];
        foreach ($siteIds as $siteId) {
            if ( ! switch_to_blog($siteId)) {
                continue;
            }

            $messages = new MessageRepository();
            $providers = new ProviderRepository();
            $providerMap = $this->providerMap($providers->getAllSafe());
            $siteName = function_exists('get_bloginfo') ? sanitize_text_field( (string) get_bloginfo('name')) : '';
            /* translators: %d: site ID. */
            $siteName = $siteName !== '' ? $siteName : sprintf(__('Site %d', 'onesmtp'), $siteId);
            $siteRows = $messages->listFilteredWithAttemptCounts($filters, 1, self::MAX_ROWS_PER_SITE);

            foreach ($siteRows as $message) {
                $rows[] = $this->summary($message, $siteId, $siteName, $providerMap);
            }

            if (function_exists('restore_current_blog')) {
                restore_current_blog();
            }
        }

        usort($rows, static function (array $left, array $right): int {
            $created = strcmp( (string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
            if ($created !== 0) {
                return $created;
            }

            return ( (int) ($right['id'] ?? 0)) <=> ( (int) ($left['id'] ?? 0));
        });

        return $rows;
    }

    /** @param array<int,array<string,mixed>> $providers @return array<int,array{name:string,type:string}> */
    private function providerMap(array $providers): array
    {
        $map = [];
        foreach ($providers as $provider) {
            $id = (int) ($provider['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $map[ $id ] = [
                'name' => $this->shortText( (string) ($provider['name'] ?? ''), 80),
                'type' => sanitize_key( (string) ($provider['adapter_type'] ?? '')),
            ];
        }

        return $map;
    }

    /** @param array<string,mixed> $message @param array<int,array{name:string,type:string}> $providers @return array<string,mixed> */
    private function summary(array $message, int $siteId, string $siteName, array $providers): array
    {
        $payload = isset($message['payload_json']) ? json_decode( (string) $message['payload_json'], true) : [];
        $payload = is_array($payload) ? $payload : [];
        $providerId = (int) ($message['selected_provider_id'] ?? 0);
        $provider = $providers[ $providerId ] ?? null;
        $providerLabel = $provider === null
            ? __('No provider', 'onesmtp')
            : trim($provider['name'] . ($provider['type'] !== '' ? ' (' . $provider['type'] . ')' : ''));

        return [
            'site_id' => $siteId,
            'site_name' => $this->shortText($siteName, 120),
            'id' => (int) ($message['id'] ?? 0),
            'message_uuid' => $this->shortText( (string) ($message['message_uuid'] ?? ''), 80),
            'status' => sanitize_key( (string) ($message['status'] ?? '')),
            'provider' => $this->shortText($providerLabel, 100),
            'attempts' => (int) ($message['attempt_count'] ?? $message['current_attempt'] ?? 0) . ' / ' . (int) ($message['max_attempts'] ?? 0),
            'switchovers' => (int) ($message['switch_count'] ?? 0),
            'source' => $this->sourceSummary($payload),
            'recipients' => $this->recipientSummary($payload),
            'attachments' => $this->attachmentSummary($payload),
            'created_at' => $this->shortText( (string) ($message['created_at'] ?? ''), 32),
            'updated_at' => $this->shortText( (string) ($message['updated_at'] ?? ''), 32),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function recipientSummary(array $payload): string
    {
        $recipients = $payload['to'] ?? [];
        if (is_string($recipients)) {
            $recipients = [$recipients];
        }
        if ( ! is_array($recipients)) {
            return __('0 recipients', 'onesmtp');
        }

        $domains = [];
        $count = 0;
        foreach (array_slice($recipients, 0, 100) as $recipient) {
            if ( ! is_scalar($recipient)) {
                continue;
            }
            $email = sanitize_email( (string) $recipient);
            if ($email === '') {
                continue;
            }
            ++$count;
            $at = strrchr($email, '@');
            $domain = $at === false ? '' : strtolower(substr($at, 1));
            if ($domain !== '') {
                $domains[ $domain ] = true;
            }
        }

        if ($count === 0) {
            return __('0 recipients', 'onesmtp');
        }

        $domainList = array_keys($domains);
        sort($domainList);

        return sprintf(
            /* translators: 1: recipient count, 2: domain list. */
            __('%1$d recipients across %2$s', 'onesmtp'),
            $count,
            $domainList !== [] ? implode(', ', array_slice($domainList, 0, 3)) : __('unknown domains', 'onesmtp')
        );
    }

    /** @param array<string,mixed> $payload */
    private function attachmentSummary(array $payload): string
    {
        $log = $payload[ AttachmentLogSanitizer::PAYLOAD_KEY ] ?? null;
        if ( ! is_array($log) || empty($log['enabled'])) {
            return __('Not logged', 'onesmtp');
        }

        return sprintf(
            /* translators: %d: attachment count. */
            _n('%d attachment', '%d attachments', max(0, (int) ($log['count'] ?? 0)), 'onesmtp'),
            max(0, (int) ($log['count'] ?? 0))
        );
    }

    /** @param array<string,mixed> $payload */
    private function sourceSummary(array $payload): string
    {
        $source = $payload['onesmtp_source'] ?? null;
        if ( ! is_array($source)) {
            return __('Unknown source', 'onesmtp');
        }

        $type = sanitize_key( (string) ($source['type'] ?? ''));
        $name = isset($source['name']) && is_scalar($source['name']) ? $this->shortText( (string) $source['name'], 80) : '';
        $name = $name !== '' ? $name : $this->shortText( (string) ($source['slug'] ?? ''), 80);
        if ($type === 'plugin') {
            /* translators: %s: safe plugin name. */
            return $name !== '' ? sprintf(__('Plugin: %s', 'onesmtp'), $name) : __('Plugin: Unknown plugin', 'onesmtp');
        }
        if ($type === 'theme') {
            /* translators: %s: safe theme name. */
            return $name !== '' ? sprintf(__('Theme: %s', 'onesmtp'), $name) : __('Theme: Unknown theme', 'onesmtp');
        }

        return $name !== '' ? $name : __('Unknown source', 'onesmtp');
    }

    /** @param array<string,mixed> $filters @return array{status:string,provider_id:int,date_from:string,date_to:string,recipient_hash:string,search:string,site_id:int} */
    private function normalizeFilters(array $filters): array
    {
        $date = static function (mixed $value): string {
            $value = trim( (string) $value);

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
        };
        $hash = strtolower(sanitize_text_field( (string) ($filters['recipient_hash'] ?? '')));

        return [
            'status' => sanitize_key( (string) ($filters['status'] ?? '')),
            'provider_id' => absint($filters['provider_id'] ?? 0),
            'date_from' => $date($filters['date_from'] ?? ''),
            'date_to' => $date($filters['date_to'] ?? ''),
            'recipient_hash' => preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : '',
            'search' => substr(sanitize_text_field( (string) ($filters['search'] ?? '')), 0, 120),
            'site_id' => absint($filters['site_id'] ?? 0),
        ];
    }

    private function shortText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/', ' ', sanitize_text_field($value)) ?? '');

        return strlen($value) > $limit ? substr($value, 0, max(1, $limit - 3)) . '...' : $value;
    }
}
