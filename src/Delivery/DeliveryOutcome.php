<?php

declare(strict_types=1);

namespace OneSMTP\Delivery;

use OneSMTP\Providers\FailureCategory;
use OneSMTP\Providers\FailureClassifier;

final class DeliveryOutcome
{
    private bool $success;
    private int $providerId;
    private string $code;
    private string $message;
    private ?string $providerMessageId;
    private string $failureCategory;
    private ?int $latencyMs;
    private bool $deferred;
    private int $retryAfter;
    private ?int $nextCapacityAt;
    private ?string $quotaReservationToken;

    public function __construct(
        bool $success,
        int $providerId,
        string $code = '',
        string $message = '',
        ?string $providerMessageId = null,
        ?string $failureCategory = null,
        ?int $latencyMs = null,
        bool $deferred = false,
        int $retryAfter = 0,
        ?int $nextCapacityAt = null,
        ?string $quotaReservationToken = null
    ) {
        $this->success           = $success;
        $this->providerId        = $providerId;
        $this->code              = $code;
        $this->message           = $message;
        $this->providerMessageId = $providerMessageId;
        $this->deferred          = $deferred;
        $this->failureCategory   = $success || $deferred ? '' : FailureCategory::normalize($failureCategory ?? FailureClassifier::classify($code, $message));
        $this->latencyMs         = $latencyMs !== null ? max(0, $latencyMs) : null;
        $this->retryAfter        = max(0, $retryAfter);
        $this->nextCapacityAt    = $nextCapacityAt !== null ? max(0, $nextCapacityAt) : null;
        $this->quotaReservationToken = $quotaReservationToken !== null && $quotaReservationToken !== '' ? $quotaReservationToken : null;
    }

    public static function deferred(int $retryAfter, ?int $nextCapacityAt = null, string $code = 'provider_quota_deferred', string $message = 'Provider sending budget is temporarily exhausted.'): self
    {
        return new self(false, 0, $code, $message, null, null, null, true, max(1, $retryAfter), $nextCapacityAt);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getProviderId(): int
    {
        return $this->providerId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function getFailureCategory(): string
    {
        return $this->failureCategory;
    }

    public function getLatencyMs(): ?int
    {
        return $this->latencyMs;
    }

    public function isDeferred(): bool
    {
        return $this->deferred;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    public function getNextCapacityAt(): ?int
    {
        return $this->nextCapacityAt;
    }

    public function getQuotaReservationToken(): ?string
    {
        return $this->quotaReservationToken;
    }

    public function shouldRetry(): bool
    {
        return ! $this->deferred && ($this->success || FailureCategory::shouldRetry($this->failureCategory));
    }
}
