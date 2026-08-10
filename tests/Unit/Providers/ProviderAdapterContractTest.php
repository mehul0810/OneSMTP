<?php

declare(strict_types=1);

namespace OneSMTPTests\Unit\Providers;

use OneSMTP\Admin\ProviderIcons;
use OneSMTP\Providers\FailureClassifier;
use OneSMTP\Providers\ProviderAdapterContractValidator;
use OneSMTP\Providers\ProviderAdapterDescriptor;
use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Providers\SendResult;
use PHPUnit\Framework\TestCase;

final class ProviderAdapterContractTest extends TestCase
{
    public function test_builtin_catalog_binds_every_adapter_to_safe_metadata_and_schema(): void
    {
        $registry = new ProviderAdapterRegistry();

        self::assertTrue($registry->isValid(), implode('; ', $registry->getValidationErrors()));
        self::assertSame([], $registry->getValidationErrors());
        self::assertSame(ProviderTypes::all(), array_keys($registry->all()));
        self::assertSame(ProviderTypes::all(), array_keys($registry->getDescriptors()));

        $metadata = ProviderTypes::metadata();
        $schemas = ProviderTypes::credentialSchema();
        $capabilityKeys = array_keys(ProviderTypes::capabilityLabels());

        foreach (ProviderTypes::all() as $slug) {
            $descriptor = $registry->getDescriptors()[ $slug ];
            self::assertInstanceOf(ProviderAdapterDescriptor::class, $descriptor);
            self::assertSame($slug, $descriptor->getSlug());
            self::assertSame($slug, $descriptor->getAdapter()->getSlug());
            self::assertSame($metadata[ $slug ], $descriptor->getMetadata());
            self::assertSame($schemas[ $slug ], $descriptor->getCredentialSchema());
            self::assertSame($capabilityKeys, array_keys($descriptor->getMetadata()['capabilities']));
            self::assertTrue($descriptor->supportsTestConnection());
            self::assertSame(
                ProviderTypes::supportsCapability($slug, 'test_send'),
                $descriptor->supportsTestSend()
            );
            self::assertSame(SendResult::class, $descriptor->getResultContract()['result_class']);
            self::assertSame(FailureClassifier::class, $descriptor->getResultContract()['failure_classifier']);
            self::assertTrue(ProviderIcons::exists($descriptor->getMetadata()['icon']));

            foreach ($descriptor->getCredentialSchema() as $field => $definition) {
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/', $field);
                self::assertIsBool($definition['required']);
                self::assertIsBool($definition['secret']);
                self::assertGreaterThan(0, $definition['max_length']);
                self::assertLessThanOrEqual(2048, $definition['max_length']);
            }
        }
    }

    public function test_malformed_duplicate_and_unregistered_declarations_fail_closed(): void
    {
        $smtp = new ProviderAdapterContractFixture('wrong_slug');
        $smtpDescriptor = new ProviderAdapterDescriptor(
            ProviderTypes::SMTP,
            $smtp,
            ProviderTypes::metadata()[ ProviderTypes::SMTP ],
            ProviderTypes::credentialSchema()[ ProviderTypes::SMTP ]
        );
        $malformed = new ProviderAdapterRegistry(
            [ProviderTypes::SMTP => $smtp],
            [ProviderTypes::SMTP => $smtpDescriptor]
        );
        self::assertFalse($malformed->isValid());
        self::assertNull($malformed->get(ProviderTypes::SMTP));
        self::assertNotEmpty($malformed->getValidationErrors());

        $first = new ProviderAdapterContractFixture(ProviderTypes::SMTP);
        $second = new ProviderAdapterContractFixture(ProviderTypes::SMTP);
        $duplicate = new ProviderAdapterRegistry(
            [
				ProviderTypes::SMTP => $first,
				'smtp_copy' => $second,
			],
            [
                ProviderTypes::SMTP => ProviderAdapterDescriptor::forProviderType(ProviderTypes::SMTP, $first),
                'smtp_copy' => ProviderAdapterDescriptor::forProviderType(ProviderTypes::SMTP, $second),
            ]
        );
        self::assertFalse($duplicate->isValid());
        self::assertNull($duplicate->get(ProviderTypes::SMTP));
        self::assertStringContainsString('more than once', implode(' ', $duplicate->getValidationErrors()));

        $unknown = new ProviderAdapterContractFixture('unknown_provider');
        $unregistered = new ProviderAdapterRegistry(
            ['unknown_provider' => $unknown],
            ['unknown_provider' => ProviderAdapterDescriptor::forProviderType('unknown_provider', $unknown)]
        );
        self::assertFalse($unregistered->isValid());
        self::assertNull($unregistered->get('unknown_provider'));
        self::assertStringContainsString('no ProviderTypes declaration', implode(' ', $unregistered->getValidationErrors()));
    }

    public function test_fixture_adapter_exposes_success_failure_and_connection_results(): void
    {
        $success = new SendResult(true, 'accepted', 'accepted');
        $failure = new SendResult(false, 'provider_timeout', 'request timed out');
        $connection = new SendResult(true, 'connected', 'connected');
        $successAdapter = new ProviderAdapterContractFixture('fixture', $success, null, $connection);
        $failureAdapter = new ProviderAdapterContractFixture('fixture', null, $failure, $connection);
        $config = new ProviderConfig(['api_key' => 'fixture-secret']);

        self::assertTrue($successAdapter->send(['to' => 'redacted@example.test'], $config)->isSuccess());
        self::assertFalse($failureAdapter->send(['to' => 'redacted@example.test'], $config)->isSuccess());
        self::assertSame('timeout', $failure->getFailureCategory());
        self::assertTrue($successAdapter->testConnection($config)->isSuccess());
        self::assertSame('fixture', $successAdapter->getSlug());
    }

    public function test_validator_rejects_non_descriptor_registration(): void
    {
        $errors = ProviderAdapterContractValidator::validate(['smtp' => 'not-an-adapter']);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('no valid descriptor', implode(' ', $errors));
    }

    public function test_malformed_capability_metadata_fails_closed_without_throwing(): void
    {
        $adapter = new ProviderAdapterContractFixture(ProviderTypes::SMTP);
        $descriptor = new ProviderAdapterDescriptor(
            ProviderTypes::SMTP,
            $adapter,
            [
                'label' => 'SMTP',
                'description' => 'fixture',
                'icon' => 'envelope',
                'capabilities' => 'malformed',
                'notes' => [],
            ],
            ProviderTypes::credentialSchema()[ ProviderTypes::SMTP ]
        );
        $registry = new ProviderAdapterRegistry(
            [ProviderTypes::SMTP => $adapter],
            [ProviderTypes::SMTP => $descriptor]
        );

        self::assertFalse($registry->isValid());
        self::assertNull($registry->get(ProviderTypes::SMTP));
        self::assertStringContainsString('capability declarations are malformed', implode(' ', $registry->getValidationErrors()));
    }
}

final class ProviderAdapterContractFixture implements ProviderAdapterInterface
{
    public function __construct(
        private string $slug,
        private ?SendResult $sendSuccess = null,
        private ?SendResult $sendFailure = null,
        private ?SendResult $connection = null
    ) {
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        return $this->sendFailure ?? $this->sendSuccess ?? new SendResult(true, 'accepted');
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->connection ?? new SendResult(true, 'connected');
    }
}
