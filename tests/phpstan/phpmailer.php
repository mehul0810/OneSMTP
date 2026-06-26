<?php

declare(strict_types=1);

namespace PHPMailer\PHPMailer;

class Exception extends \Exception
{
}

class PHPMailer
{
    public const ENCRYPTION_STARTTLS = 'tls';

    public string $Host = '';
    public int $Port = 587;
    public bool $SMTPAuth = true;
    public string $Username = '';
    public string $Password = '';
    public string $SMTPSecure = '';
    public int $Timeout = 30;
    public string $Subject = '';
    public string $Body = '';

    public function __construct(bool $exceptions = false)
    {
        unset($exceptions);
    }

    public function isSMTP(): void
    {
    }

    public function setFrom(string $address, string $name = ''): bool
    {
        return true;
    }

    public function addAddress(string $address, string $name = ''): bool
    {
        return true;
    }

    public function addReplyTo(string $address, string $name = ''): bool
    {
        return true;
    }

    public function addBCC(string $address, string $name = ''): bool
    {
        return true;
    }

    public function isHTML(bool $isHtml = true): void
    {
    }

    public function send(): bool
    {
        return true;
    }

    public function getLastMessageID(): string
    {
        return '';
    }
}
