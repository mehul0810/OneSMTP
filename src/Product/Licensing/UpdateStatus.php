<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

final class UpdateStatus
{
    private const REASONS = [ 'not_configured', 'current', 'update_available', 'service_error' ];

    private function __construct(private readonly UpdateState $state, private readonly string $reason)
    {
    }

    public static function create(UpdateState $state, string $reason = 'not_configured'): self
    {
        if ( ! in_array($reason, self::REASONS, true)) {
            $reason = 'service_error';
        }

        return new self($state, $reason);
    }

    public static function unavailable(): self
    {
        return self::create(UpdateState::UNAVAILABLE);
    }

    public function state(): UpdateState
    {
        return $this->state;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
