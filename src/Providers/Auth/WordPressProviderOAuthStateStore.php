<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

/** One-time state storage backed by bounded WordPress transients. */
final class WordPressProviderOAuthStateStore implements ProviderOAuthStateStoreInterface
{
    private const PREFIX = 'onesmtp_oauth_state_';

    public function put(string $stateHash, array $record, int $ttl): bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $stateHash) !== 1) {
            return false;
        }

        return set_transient(self::PREFIX . $stateHash, $record, max(60, min(300, $ttl)));
    }

    public function consume(string $stateHash): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $stateHash) !== 1) {
            return null;
        }

        $key = self::PREFIX . $stateHash;
        $record = get_transient($key);
        delete_transient($key);

        return is_array($record) ? $record : null;
    }
}
