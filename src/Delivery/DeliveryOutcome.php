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

    public function __construct(
        bool $success,
        int $providerId,
        string $code = '',
        string $message = '',
        ?string $providerMessageId = null,
        ?string $failureCategory = null,
        ?int $latencyMs = null
    ) {
        $this->success           = $success;
        $this->providerId        = $providerId;
        $this->code              = $code;
        $this->message           = $message;
        $this->providerMessageId = $providerMessageId;
        $this->failureCategory   = $success ? '' : FailureCategory::normalize($failureCategory ?? FailureClassifier::classify($code, $message));
        $this->latencyMs         = $latencyMs !== null ? max(0, $latencyMs) : null;
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

    public function shouldRetry(): bool
    {
        return $this->success || FailureCategory::shouldRetry($this->failureCategory);
    }
}
