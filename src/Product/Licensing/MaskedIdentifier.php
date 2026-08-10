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

        $normalized = preg_replace('/[^A-Za-z0-9]/', '', $identifier) ?? '';
        if (strlen($normalized) <= 4) {
            return new self('****');
        }

        return new self('****' . substr($normalized, -4));
    }

    public function value(): string
    {
        return $this->value;
    }
}
