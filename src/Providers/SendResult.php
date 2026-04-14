<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

final class SendResult
{
    private bool $success;
    private string $code;
    private string $message;
    private ?string $providerMessageId;

    public function __construct(bool $success, string $code = '', string $message = '', ?string $providerMessageId = null)
    {
        $this->success           = $success;
        $this->code              = $code;
        $this->message           = $message;
        $this->providerMessageId = $providerMessageId;
    }

    public function isSuccess(): bool
    {
        return $this->success;
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
}
