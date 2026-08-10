<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

interface ProviderOAuthStateStoreInterface
{
    /** @param array<string,mixed> $record */
    public function put(string $stateHash, array $record, int $ttl): bool;

    /** @return array<string,mixed>|null */
    public function consume(string $stateHash): ?array;
}
