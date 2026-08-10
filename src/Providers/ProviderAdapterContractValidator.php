<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

use ReflectionClass;

/**
 * Validates the additive adapter catalog and returns errors instead of
 * throwing. Registries can therefore fail closed without taking down a site.
 */
final class ProviderAdapterContractValidator
{
    /**
     * @param array<string,mixed> $descriptors
     * @return array<int,string>
     */
    public static function validate(array $descriptors): array
    {
        $errors = [];
        $seenSlugs = [];

        foreach ($descriptors as $registrationSlug => $descriptor) {
            $registrationSlug = (string) $registrationSlug;
            if ( ! $descriptor instanceof ProviderAdapterDescriptor) {
                $errors[] = sprintf('Registration %s has no valid descriptor.', $registrationSlug);
                continue;
            }

            $slug = $descriptor->getSlug();
            if ( ! self::isSlug($slug)) {
                $errors[] = sprintf('Adapter slug %s is malformed.', $slug);
            }
            if ($registrationSlug !== $slug) {
                $errors[] = sprintf('Registration key %s does not match adapter slug %s.', $registrationSlug, $slug);
            }
            if (isset($seenSlugs[ $slug ])) {
                $errors[] = sprintf('Adapter slug %s is registered more than once.', $slug);
            }
            $seenSlugs[ $slug ] = true;

            if ( ! ProviderTypes::isSupported($slug)) {
                $errors[] = sprintf('Adapter slug %s has no ProviderTypes declaration.', $slug);
                continue;
            }

            $adapter = $descriptor->getAdapter();
            if ($adapter->getSlug() !== $slug) {
                $errors[] = sprintf('Adapter registration %s does not match getSlug().', $slug);
            }

            $expectedMetadata = ProviderTypes::metadata()[ $slug ];
            if ($descriptor->getMetadata() !== $expectedMetadata) {
                $errors[] = sprintf('Adapter %s metadata does not match ProviderTypes.', $slug);
            }

            $capabilities = $descriptor->getMetadata()['capabilities'] ?? [];
            if ( ! is_array($capabilities)) {
                $errors[] = sprintf('Adapter %s capability declarations are malformed.', $slug);
                $capabilities = [];
            }
            if (array_keys($capabilities) !== array_keys(ProviderTypes::capabilityLabels())) {
                $errors[] = sprintf('Adapter %s capability declarations are incomplete or unordered.', $slug);
            }
            foreach ($capabilities as $capability => $available) {
                if ( ! is_bool($available)) {
                    $errors[] = sprintf('Adapter %s capability %s is not boolean.', $slug, (string) $capability);
                }
            }
            if ($descriptor->supportsTestSend() !== ProviderTypes::supportsCapability($slug, 'test_send')) {
                $errors[] = sprintf('Adapter %s test-send capability is inconsistent.', $slug);
            }

            self::validateMethods($slug, $adapter, $errors);
            self::validateCredentialSchema($slug, $descriptor->getCredentialSchema(), $errors);

            $resultContract = $descriptor->getResultContract();
            if ($resultContract['result_class'] !== SendResult::class || $resultContract['failure_classifier'] !== FailureClassifier::class) {
                $errors[] = sprintf('Adapter %s result mapping is not normalized.', $slug);
            }
        }

        foreach (ProviderTypes::all() as $slug) {
            if ( ! isset($seenSlugs[ $slug ])) {
                $errors[] = sprintf('Provider type %s is not registered with an adapter.', $slug);
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<int,string> $errors */
    private static function validateMethods(string $slug, ProviderAdapterInterface $adapter, array &$errors): void
    {
        $reflection = new ReflectionClass($adapter);
        foreach (['send', 'testConnection'] as $methodName) {
            if ( ! $reflection->hasMethod($methodName) || ! $reflection->getMethod($methodName)->isPublic()) {
                $errors[] = sprintf('Adapter %s does not expose public %s behavior.', $slug, $methodName);
            }
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<int,string> $errors
     */
    private static function validateCredentialSchema(string $slug, array $schema, array &$errors): void
    {
        if (count($schema) > 24) {
            $errors[] = sprintf('Adapter %s declares too many credential fields.', $slug);
        }

        foreach ($schema as $field => $definition) {
            $field = (string) $field;
            if ( ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field)) {
                $errors[] = sprintf('Adapter %s has malformed credential field %s.', $slug, $field);
                continue;
            }
            if ( ! is_array($definition)) {
                $errors[] = sprintf('Adapter %s credential field %s is malformed.', $slug, $field);
                continue;
            }

            $type = (string) ($definition['type'] ?? '');
            if ( ! in_array($type, ['string', 'integer', 'boolean', 'email'], true)) {
                $errors[] = sprintf('Adapter %s credential field %s has an unsupported type.', $slug, $field);
            }
            if ( ! is_bool($definition['required'] ?? null) || ! is_bool($definition['secret'] ?? null)) {
                $errors[] = sprintf('Adapter %s credential field %s has invalid flags.', $slug, $field);
            }
            $maxLength = $definition['max_length'] ?? 0;
            if ( ! is_int($maxLength) || $maxLength < 1 || $maxLength > 2048) {
                $errors[] = sprintf('Adapter %s credential field %s exceeds the value bound.', $slug, $field);
            }
            $enum = $definition['enum'] ?? [];
            if ( ! is_array($enum) || count($enum) > 32) {
                $errors[] = sprintf('Adapter %s credential field %s has an invalid enum.', $slug, $field);
                continue;
            }
            foreach ($enum as $option) {
                if ( ! is_string($option) || strlen($option) > 64) {
                    $errors[] = sprintf('Adapter %s credential field %s has an invalid enum option.', $slug, $field);
                    break;
                }
            }
        }
    }

    private static function isSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{0,63}$/', $slug);
    }
}
