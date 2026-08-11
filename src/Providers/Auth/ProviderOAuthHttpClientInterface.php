<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

interface ProviderOAuthHttpClientInterface
{
    /**
     * @param array<string,string> $headers
     * @param array<string,string> $body
     */
    public function post(string $url, array $headers, array $body, int $timeout = 15): ProviderOAuthHttpResponse;
}
