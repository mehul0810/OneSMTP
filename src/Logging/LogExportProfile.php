<?php

declare(strict_types=1);

namespace OneSMTP\Logging;

/**
 * Fixed, privacy-safe CSV profiles for operational log exports.
 *
 * Profiles are intentionally allowlists. They cannot expose a raw database
 * column or an arbitrary payload key, even when a caller supplies a crafted
 * profile value.
 */
final class LogExportProfile
{
    public const DEFAULT_PROFILE = 'operational';

    /**
     * @return array<string,array{label:string,description:string,fields:array<int,string>}>
     */
    public static function profiles(): array
    {
        return [
            self::DEFAULT_PROFILE => [
                'label' => __('Operational summary', 'onesmtp'),
                'description' => __('Status, provider, retry, attachment, recipient-domain, and timestamp summaries.', 'onesmtp'),
                'fields' => [
                    'message_id',
                    'lineage_uuid',
                    'status',
                    'provider',
                    'attempt_count',
                    'max_attempts',
                    'attachment_summary',
                    'recipient_summary',
                    'next_retry_at',
                    'created_at',
                    'updated_at',
                ],
            ],
            'audit' => [
                'label' => __('Audit summary', 'onesmtp'),
                'description' => __('Identifiers, outcome, provider, attempts, and timestamps without recipient or attachment summaries.', 'onesmtp'),
                'fields' => [
                    'message_id',
                    'lineage_uuid',
                    'status',
                    'provider',
                    'attempt_count',
                    'max_attempts',
                    'created_at',
                    'updated_at',
                ],
            ],
            'minimal' => [
                'label' => __('Minimal record', 'onesmtp'),
                'description' => __('Identifiers, outcome, and timestamps only.', 'onesmtp'),
                'fields' => [
                    'message_id',
                    'lineage_uuid',
                    'status',
                    'created_at',
                    'updated_at',
                ],
            ],
        ];
    }

    public static function normalize(string $profile): string
    {
        $profile = sanitize_key($profile);

        return isset(self::profiles()[$profile]) ? $profile : self::DEFAULT_PROFILE;
    }

    /** @return array<int,string> */
    public static function fields(string $profile): array
    {
        $profile = self::normalize($profile);

        return self::profiles()[$profile]['fields'];
    }
}
