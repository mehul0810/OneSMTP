<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

/** One-time state storage backed by bounded WordPress transients. */
final class WordPressProviderOAuthStateStore implements ProviderOAuthStateStoreInterface
{
    private const PREFIX = 'onesmtp_oauth_state_';
    private const CLAIM_PREFIX = 'onesmtp_oauth_state_claim_';

    /** @var callable():int */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

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
        $claimKey = self::CLAIM_PREFIX . $stateHash;
        $now = (int) ($this->clock)();

        try {
            $existingClaim = get_option($claimKey, false);
            if (is_array($existingClaim) && (int) ($existingClaim['expires_at'] ?? 0) <= $now) {
                delete_option($claimKey);
            }

            // add_option() is backed by a unique option_name insert. It is
            // the database-side fence that prevents two callbacks from both
            // consuming a transient when an external object cache is absent.
            if ( ! add_option(
                $claimKey,
                [
                    'claimed_at' => $now,
                    'expires_at' => $now + 300,
                ],
                '',
                false
            )) {
                return null;
            }

            $record = get_transient($key);
            delete_transient($key);
            delete_option($claimKey);
        } catch (\Throwable $exception) {
            unset($exception);
            delete_option($claimKey);

            return null;
        }

        return is_array($record) ? $record : null;
    }
}
