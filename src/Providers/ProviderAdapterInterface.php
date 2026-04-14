<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

interface ProviderAdapterInterface
{
    public function getSlug(): string;

    public function send(array $message, ProviderConfig $config): SendResult;

    public function testConnection(ProviderConfig $config): SendResult;
}
