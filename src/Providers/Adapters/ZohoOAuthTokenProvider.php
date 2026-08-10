<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;
use OneSMTP\Providers\Auth\ProviderOAuthTokenService;

final class ZohoOAuthTokenProvider
{
    public function __construct(private ?ProviderOAuthTokenService $service = null)
    {
        $this->service = $service ?? new ProviderOAuthTokenService();
    }

    /** @return string|SendResult */
    public function accessToken(ProviderConfig $config): string|SendResult
    {
        return $this->service->accessToken('zoho_mail', $config);
    }
}
