<?php

declare(strict_types=1);

namespace OneSMTP\Dns;

final class NativeDnsResolver implements DnsResolverInterface
{
    public function isAvailable(): bool
    {
        return function_exists('dns_get_record');
    }

    public function txt(string $domain): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $records = @dns_get_record($domain, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $txt = isset($record['txt']) ? (string) $record['txt'] : '';
            if ($txt !== '') {
                $values[] = $txt;
            }
        }

        return $values;
    }
}
