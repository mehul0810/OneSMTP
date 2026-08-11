<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Providers;

use OneSMTP\Providers\Auth\ProviderOAuthDescriptor;
use OneSMTP\Providers\ProviderTypes;
use PHPUnit\Framework\TestCase;

final class ProviderOAuthDescriptorTest extends TestCase
{
    public function test_zoho_canada_uses_the_zohocloud_auth_token_and_revoke_host(): void
    {
        $descriptor = ProviderOAuthDescriptor::forProvider(ProviderTypes::ZOHO_MAIL, 'ca');

        self::assertNotNull($descriptor);
        self::assertSame('https://accounts.zohocloud.ca/oauth/v2/auth', $descriptor->getAuthorizationEndpoint());
        self::assertSame('https://accounts.zohocloud.ca/oauth/v2/token', $descriptor->getTokenEndpoint());
        self::assertSame('https://accounts.zohocloud.ca/oauth/v2/token/revoke', $descriptor->getRevokeEndpoint());
    }
}
