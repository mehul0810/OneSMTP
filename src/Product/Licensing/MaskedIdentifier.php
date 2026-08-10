<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

final class MaskedIdentifier
{
    private function __construct(private readonly string $value)
    {
    }

    public static function none(): self
    {
        return new self('');
    }

    public static function fromRaw(string $identifier): self
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return self::none();
        }

        $suffix = substr($identifier, -4);
        $suffix = preg_replace('/[^A-Za-z0-9]/', '', $suffix) ?? '';

        return new self($suffix === '' ? '****' : '****' . substr($suffix, -4));
    }

    public function value(): string
    {
        return $this->value;
    }
}
