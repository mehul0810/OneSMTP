<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

final class LicenseStatus
{
    private const REASONS = [
        'not_configured',
        'inactive',
        'active',
        'invalid',
        'expired',
        'service_error',
    ];

    private function __construct(
        private readonly LicenseState $state,
        private readonly MaskedIdentifier $identifier,
        private readonly string $reason
    ) {
    }

    public static function create(
        LicenseState $state,
        ?MaskedIdentifier $identifier = null,
        string $reason = 'not_configured'
    ): self {
        if ( ! in_array($reason, self::REASONS, true)) {
            $reason = 'service_error';
        }

        return new self($state, $identifier ?? MaskedIdentifier::none(), $reason);
    }

    public static function unavailable(): self
    {
        return self::create(LicenseState::UNAVAILABLE);
    }

    public function state(): LicenseState
    {
        return $this->state;
    }

    public function maskedIdentifier(): string
    {
        return $this->identifier->value();
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array{state:string,masked_identifier:string,reason:string} */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'masked_identifier' => $this->maskedIdentifier(),
            'reason' => $this->reason,
        ];
    }
}
