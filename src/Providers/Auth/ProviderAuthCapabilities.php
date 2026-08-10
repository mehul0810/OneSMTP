<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

/**
 * Side-effect-free declarations for future reconnect and revoke controls.
 */
final class ProviderAuthCapabilities
{
    public function __construct(
        private bool $canReconnect,
        private bool $canRevoke
    ) {
    }

    public static function none(): self
    {
        return new self(false, false);
    }

    public static function reconnectOnly(): self
    {
        return new self(true, false);
    }

    public static function reconnectAndRevoke(): self
    {
        return new self(true, true);
    }

    public function canReconnect(): bool
    {
        return $this->canReconnect;
    }

    public function canRevoke(): bool
    {
        return $this->canRevoke;
    }

    /**
     * @return array{can_reconnect:bool,can_revoke:bool}
     */
    public function toArray(): array
    {
        return [
            'can_reconnect' => $this->canReconnect,
            'can_revoke' => $this->canRevoke,
        ];
    }
}
