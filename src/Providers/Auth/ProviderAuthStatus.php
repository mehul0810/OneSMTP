<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

/**
 * Redacted provider-auth status DTO. It is safe to pass to future UI or
 * diagnostics layers because it contains only bounded state and capabilities.
 */
final class ProviderAuthStatus
{
    public function __construct(
        private ProviderAuthState $state,
        private ProviderAuthCapabilities $capabilities
    ) {
    }

    public static function forState(ProviderAuthState $state, ?ProviderAuthCapabilities $capabilities = null): self
    {
        return new self($state, $capabilities ?? ProviderAuthCapabilities::none());
    }

    public function getState(): ProviderAuthState
    {
        return $this->state;
    }

    public function getCapabilities(): ProviderAuthCapabilities
    {
        return $this->capabilities;
    }

    public function canReconnect(): bool
    {
        return $this->capabilities->canReconnect();
    }

    public function canRevoke(): bool
    {
        return $this->capabilities->canRevoke();
    }

    /**
     * @return array{state:string,can_reconnect:bool,can_revoke:bool}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            ...$this->capabilities->toArray(),
        ];
    }
}
