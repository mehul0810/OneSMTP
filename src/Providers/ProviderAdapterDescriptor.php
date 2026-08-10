<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

/**
 * Additive declaration for an adapter's public extension contract.
 *
 * Existing adapters keep the small ProviderAdapterInterface. The descriptor
 * binds registration, catalog metadata, capability flags, bounded connection
 * fields, and the normalized result contract without adding methods that
 * third-party adapters would have to implement.
 */
final class ProviderAdapterDescriptor
{
    /**
     * @param array<string,mixed> $metadata Metadata is validated before use.
     * @param array<string,array{type:string,required:bool,secret:bool,max_length:int,enum?:array<int,string>}> $credentialSchema
     */
    public function __construct(
        private string $slug,
        private ProviderAdapterInterface $adapter,
        private array $metadata,
        private array $credentialSchema
    ) {
    }

    public static function forProviderType(string $slug, ProviderAdapterInterface $adapter): self
    {
        $metadata = ProviderTypes::metadata()[ $slug ] ?? [
            'label' => '',
            'description' => '',
            'icon' => '',
            'capabilities' => [],
            'notes' => [],
        ];

        return new self(
            $slug,
            $adapter,
            $metadata,
            ProviderTypes::credentialSchema()[ $slug ] ?? []
        );
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getAdapter(): ProviderAdapterInterface
    {
        return $this->adapter;
    }

    /** @return array{label:string,description:string,icon:string,capabilities:array<string,bool>,notes:array<string,string>} */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Return the raw capability declaration for validation. It is deliberately
     * mixed because extension input must be rejected safely at the boundary.
     */
    public function getCapabilities(): mixed
    {
        return $this->metadata['capabilities'] ?? null;
    }

    /** @return array<string,array{type:string,required:bool,secret:bool,max_length:int,enum?:array<int,string>}> */
    public function getCredentialSchema(): array
    {
        return $this->credentialSchema;
    }

    public function supportsTestConnection(): bool
    {
        return true;
    }

    public function supportsTestSend(): bool
    {
        $capabilities = $this->getCapabilities();

        return is_array($capabilities) && ($capabilities['test_send'] ?? null) === true;
    }

    /** @return array{result_class:class-string<SendResult>,failure_classifier:class-string<FailureClassifier>} */
    public function getResultContract(): array
    {
        return [
            'result_class' => SendResult::class,
            'failure_classifier' => FailureClassifier::class,
        ];
    }
}
