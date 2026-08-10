<?php

declare(strict_types=1);

namespace OneSMTP\Quota;

final class ProviderQuotaDecision
{
    public function __construct(
        private bool $allowed,
        private int $retryAfter = 0,
        private ?int $nextCapacityAt = null,
        private int $providerId = 0,
        private string $window = '',
        private int $limit = 0,
        private int $used = 0
    ) {
        $this->retryAfter = max(0, $retryAfter);
        $this->nextCapacityAt = $nextCapacityAt !== null ? max(0, $nextCapacityAt) : null;
        $this->providerId = max(0, $providerId);
        $this->limit = max(0, $limit);
        $this->used = max(0, $used);
    }

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function deferred(
        int $retryAfter,
        ?int $nextCapacityAt = null,
        int $providerId = 0,
        string $window = '',
        int $limit = 0,
        int $used = 0
    ): self {
        return new self(false, $retryAfter, $nextCapacityAt, $providerId, $window, $limit, $used);
    }

    public function canSend(): bool
    {
        return $this->allowed;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    public function getNextCapacityAt(): ?int
    {
        return $this->nextCapacityAt;
    }

    public function getProviderId(): int
    {
        return $this->providerId;
    }

    public function getWindow(): string
    {
        return $this->window;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getUsed(): int
    {
        return $this->used;
    }
}
