<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

final class AdminScreenDefinition
{
    /**
     * @param callable(array<int,array<string,mixed>>,array<string,mixed>):void $renderer
     * @param array<int,string> $aliases
     */
    public function __construct(
        private string $id,
        private string $title,
        private string $description,
        private $renderer,
        private array $aliases = []
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @return array<int,string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /**
     * @param array<int,array<string,mixed>> $activeProviders
     * @param array<string,mixed> $senderIdentity
     */
    public function render(array $activeProviders, array $senderIdentity): void
    {
        ($this->renderer)($activeProviders, $senderIdentity);
    }
}
