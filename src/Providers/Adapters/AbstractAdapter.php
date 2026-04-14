<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderConfig;

abstract class AbstractAdapter
{
    protected function normalizeRecipients($to): array
    {
        if (is_string($to)) {
            $to = array_filter(array_map('trim', explode(',', $to)));
        }

        if (! is_array($to)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $to), static fn (string $email): bool => $email !== ''));
    }

    protected function extractFrom(array $payload, ProviderConfig $config): string
    {
        $from = (string) ($payload['from'] ?? $config->get('from_email', get_bloginfo('admin_email')));

        return sanitize_email($from);
    }
}
