<?php

declare(strict_types=1);

namespace OneSMTP\Dns;

interface DnsResolverInterface
{
    public function isAvailable(): bool;

    /**
     * @return array<int,string>
     */
    public function txt(string $domain): array;
}
