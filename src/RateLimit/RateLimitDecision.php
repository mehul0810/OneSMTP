<?php

declare(strict_types=1);

namespace OneSMTP\RateLimit;

final class RateLimitDecision
{
    public function __construct(
        private bool $allowed,
        private int $retryAfter = 0,
        private string $window = '',
        private int $limit = 0,
        private int $used = 0
    ) {
        $this->retryAfter = max(0, $retryAfter);
        $this->limit = max(0, $limit);
        $this->used = max(0, $used);
    }

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function limited(int $retryAfter, string $window, int $limit, int $used): self
    {
        return new self(false, $retryAfter, $window, $limit, $used);
    }

    public function canSend(): bool
    {
        return $this->allowed;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
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
