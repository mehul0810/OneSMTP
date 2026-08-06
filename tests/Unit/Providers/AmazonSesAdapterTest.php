<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Providers;

use OneSMTP\Providers\Adapters\AmazonSesAdapter;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderTypes;
use PHPUnit\Framework\TestCase;

final class AmazonSesAdapterTest extends TestCase
{
    public function test_registry_exposes_amazon_ses_adapter(): void
    {
        $adapter = (new ProviderAdapterRegistry())->get(ProviderTypes::AMAZON_SES);

        self::assertInstanceOf(AmazonSesAdapter::class, $adapter);
        self::assertSame('amazon_ses', $adapter->getSlug());
    }

    public function test_endpoint_is_derived_from_sanitized_region(): void
    {
        self::assertSame('email-smtp.ap-south-1.amazonaws.com', AmazonSesAdapter::endpointForRegion(' AP-SOUTH-1 '));
        self::assertSame('', AmazonSesAdapter::endpointForRegion('***'));
    }
}
