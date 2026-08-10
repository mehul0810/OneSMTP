<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Product;

use OneSMTP\Product\FeatureGate;
use OneSMTP\Product\Licensing\FeatureGateAdapter;
use OneSMTP\Product\Licensing\EntitlementProvider;
use OneSMTP\Product\Licensing\LicenseClient;
use OneSMTP\Product\Licensing\LicenseEntitlementProvider;
use OneSMTP\Product\Licensing\LicenseState;
use OneSMTP\Product\Licensing\LicenseStatus;
use OneSMTP\Product\Licensing\MaskedIdentifier;
use OneSMTP\Tests\Support\FakeEntitlementProvider;
use OneSMTP\Tests\Support\FakeLicenseClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LicensingFoundationTest extends TestCase
{
    public function test_identifier_is_masked_before_it_crosses_the_status_boundary(): void
    {
        $raw = 'license-secret-AB12';
        $status = LicenseStatus::create(LicenseState::ACTIVE, MaskedIdentifier::fromRaw($raw), 'active');

        self::assertSame('****AB12', $status->maskedIdentifier());
        self::assertStringNotContainsString($raw, (string) wp_json_encode($status->toArray()));
    }

    public function test_short_identifiers_are_fully_masked_and_empty_values_stay_empty(): void
    {
        self::assertSame('****', MaskedIdentifier::fromRaw('A')->value());
        self::assertSame('****', MaskedIdentifier::fromRaw('AB12')->value());
        self::assertSame('****', MaskedIdentifier::fromRaw('A-B')->value());
        self::assertSame('', MaskedIdentifier::fromRaw('')->value());
        self::assertSame('', MaskedIdentifier::fromRaw('   ')->value());
    }

    public function test_unknown_reason_is_reduced_to_a_bounded_error_code(): void
    {
        $status = LicenseStatus::create(LicenseState::ERROR, reason: 'remote diagnostic with a key');

        self::assertSame('service_error', $status->reason());
    }

    public function test_only_active_license_status_supplies_an_entitlement(): void
    {
        foreach (LicenseState::cases() as $state) {
            $provider = new LicenseEntitlementProvider(new FakeLicenseClient(
                LicenseStatus::create($state, reason: $state === LicenseState::ACTIVE ? 'active' : 'inactive')
            ));

            self::assertSame($state === LicenseState::ACTIVE, $provider->hasProEntitlement());
        }
    }

    public function test_feature_gate_adapter_still_requires_a_rollout_flag(): void
    {
        $adapter = new FeatureGateAdapter(new FakeEntitlementProvider(true));

        self::assertFalse($adapter->create()->isEnabled(FeatureGate::SMART_ROUTING));
        self::assertTrue($adapter->create([FeatureGate::SMART_ROUTING => true])->isEnabled(FeatureGate::SMART_ROUTING));
    }

    public function test_feature_gate_adapter_denies_flags_without_entitlement(): void
    {
        $gate = (new FeatureGateAdapter(new FakeEntitlementProvider(false)))->create([
            FeatureGate::SMART_ROUTING => true,
        ]);

        self::assertFalse($gate->isEnabled(FeatureGate::SMART_ROUTING));
        self::assertSame(FeatureGate::STATE_UPGRADE_REQUIRED, $gate->state(FeatureGate::SMART_ROUTING));
    }

    public function test_entitlement_and_adapter_exceptions_fail_closed(): void
    {
        $client = new class() implements LicenseClient {
            public function status(): LicenseStatus
            {
                throw new RuntimeException('private service diagnostic');
            }

            public function activate(string $licenseKey): LicenseStatus
            {
                throw new RuntimeException('unused');
            }

            public function deactivate(): LicenseStatus
            {
                throw new RuntimeException('unused');
            }

            public function refresh(): LicenseStatus
            {
                throw new RuntimeException('unused');
            }
        };
        self::assertFalse((new LicenseEntitlementProvider($client))->hasProEntitlement());

        $throwing = new class() implements EntitlementProvider {
            public function hasProEntitlement(): bool
            {
                throw new RuntimeException('private entitlement diagnostic');
            }
        };
        $gate = (new FeatureGateAdapter($throwing))->create([FeatureGate::SMART_ROUTING => true]);

        self::assertFalse($gate->isEnabled(FeatureGate::SMART_ROUTING));
    }
}
