<?php

declare(strict_types=1);

namespace OneSMTP\Migration;

use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\ProviderRepository;

/**
 * Read-only SureMail discovery plus an explicit, fingerprinted import.
 *
 * This service never loads SureMail classes, copies logs, changes its options,
 * or deactivates plugins. Credential material exists in memory only and is
 * handed to ProviderRepository, which stores it through the AES-GCM vault.
 */
final class SureMailMigrationService
{
    private const OPTION = 'suremails_connections';

    /** @var array<string,string> */
    private const TYPES = [
        'SMTP' => ProviderTypes::SMTP,
        'AWS' => ProviderTypes::AMAZON_SES,
        'PHPMail' => ProviderTypes::PHP_MAIL,
        'PHPMAIL' => ProviderTypes::PHP_MAIL,
        'GMAIL' => ProviderTypes::GMAIL,
        'SENDGRID' => ProviderTypes::SENDGRID,
        'POSTMARK' => ProviderTypes::POSTMARK,
        'BREVO' => ProviderTypes::BREVO,
        'MAILGUN' => ProviderTypes::MAILGUN,
        'MAILJET' => ProviderTypes::MAILJET,
        'SPARKPOST' => ProviderTypes::SPARKPOST,
        'MAILERSEND' => ProviderTypes::MAILERSEND,
        'SMTP2GO' => ProviderTypes::SMTP2GO,
        'ELASTIC' => ProviderTypes::ELASTIC_EMAIL,
        'ELASTICEMAIL' => ProviderTypes::ELASTIC_EMAIL,
        'ZEPTO' => ProviderTypes::ZEPTOMAIL,
        'ZEPTOMAIL' => ProviderTypes::ZEPTOMAIL,
        'ZOHO' => ProviderTypes::ZOHO_MAIL,
        'EMAILIT' => ProviderTypes::EMAILIT,
        'NETCORE' => ProviderTypes::NETCORE,
    ];

    /** @var array<string,list<string>> */
    private const ENCRYPTED_FIELDS = [
        'SMTP' => ['password'],
		'AWS' => ['access_key', 'secret_key', 'password'],
        'GMAIL' => ['client_secret', 'refresh_token', 'access_token'],
        'SENDGRID' => ['api_key'],
		'POSTMARK' => ['api_key', 'server_token'],
		'BREVO' => ['api_key'],
        'MAILGUN' => ['api_key'],
		'MAILJET' => ['api_key', 'secret_key'],
        'SPARKPOST' => ['api_key'],
		'MAILERSEND' => ['api_key'],
		'SMTP2GO' => ['api_key'],
        'ELASTIC' => ['api_key'],
		'ELASTICEMAIL' => ['api_key'],
        'ZEPTO' => ['api_key'],
		'ZEPTOMAIL' => ['api_key'],
        'ZOHO' => ['client_secret', 'auth_code', 'refresh_token', 'access_token'],
        'EMAILIT' => ['api_key'],
		'NETCORE' => ['api_key'],
    ];

    /** @var array<string,list<string>> */
    private const REQUIRED_CONFIG = [
        ProviderTypes::SMTP => ['host'],
        ProviderTypes::AMAZON_SES => ['region', 'username', 'password'],
        ProviderTypes::GMAIL => ['client_id', 'client_secret', 'refresh_token'],
        ProviderTypes::SENDGRID => ['api_key'],
		ProviderTypes::POSTMARK => ['api_key'],
        ProviderTypes::BREVO => ['api_key'],
		ProviderTypes::MAILGUN => ['api_key', 'domain'],
        ProviderTypes::MAILJET => ['api_key', 'secret_key'],
		ProviderTypes::SPARKPOST => ['api_key'],
        ProviderTypes::MAILERSEND => ['api_key'],
		ProviderTypes::SMTP2GO => ['api_key'],
        ProviderTypes::ELASTIC_EMAIL => ['api_key'],
		ProviderTypes::ZEPTOMAIL => ['api_key'],
        ProviderTypes::ZOHO_MAIL => ['account_id', 'client_id', 'client_secret', 'refresh_token'],
        ProviderTypes::EMAILIT => ['api_key'],
		ProviderTypes::NETCORE => ['api_key', 'region'],
    ];

    public function __construct(private ?ProviderRepository $providers = null)
    {
        $this->providers = $providers ?? new ProviderRepository();
    }

    /** @return array<string,mixed> */
    public function analyze(): array
    {
        $settings = get_option(self::OPTION, []);
        $settings = is_array($settings) ? $settings : [];
        $connections = isset($settings['connections']) && is_array($settings['connections']) ? $settings['connections'] : [];
        $default = isset($settings['default_connection']) && is_array($settings['default_connection']) ? $settings['default_connection'] : [];
        $defaultId = sanitize_text_field( (string) ($default['id'] ?? ''));
        $connection = $defaultId !== '' && isset($connections[ $defaultId ]) && is_array($connections[ $defaultId ])
            ? $connections[ $defaultId ]
            : null;
        $sourceType = is_array($connection) ? strtoupper(sanitize_key( (string) ($connection['type'] ?? ''))) : '';
        $sourceType = str_replace('_', '', $sourceType);
        $targetType = self::TYPES[ $sourceType ] ?? '';
        $existing = $targetType !== '' ? $this->findByType($targetType) : null;
        $sourceVersion = defined('SUREMAILS_VERSION') ? sanitize_text_field( (string) constant('SUREMAILS_VERSION')) : '';
        $versionSupported = $sourceVersion === '' || (version_compare($sourceVersion, '1.3.0', '>=') && version_compare($sourceVersion, '3.0.0', '<'));
        $decodedConnection = is_array($connection) ? $this->decodeConnection($sourceType, $connection) : [];
        $config = $targetType !== '' ? $this->mapConfig($targetType, $decodedConnection) : [];
        $missingFields = $targetType !== '' ? $this->missingRequiredFields($targetType, $config) : [];
        $providerSupported = $connection !== null && ProviderTypes::isSupported($targetType);

        return [
            'detected' => defined('SUREMAILS_FILE') || isset($connections[ $defaultId ]) || (function_exists('is_plugin_active') && is_plugin_active('suremails/suremails.php')),
            'connection_count' => count($connections),
            'default_id' => $defaultId,
            'default_name' => sanitize_text_field( (string) (($connection['connection_title'] ?? '') ?: ($default['connection_title'] ?? ''))),
            'source_type' => $sourceType,
            'target_type' => $targetType,
            'supported' => $providerSupported,
            'importable' => $providerSupported && $versionSupported && $missingFields === [],
            'source_version' => $sourceVersion,
            'version_supported' => $versionSupported,
            'missing_fields' => $missingFields,
            'already_configured' => is_array($existing),
            'skipped_connections' => max(0, count($connections) - ($connection === null ? 0 : 1)),
            'fingerprint' => $connection === null ? '' : hash('sha256', wp_json_encode([$defaultId, $connection])),
        ];
    }

    /** @return array{ok:bool,reason:string,provider_id:int} */
    public function import(string $expectedFingerprint): array
    {
        $analysis = $this->analyze();
        if (empty($analysis['importable'])) {
            return [
				'ok' => false,
				'reason' => 'unsupported',
				'provider_id' => 0,
			];
        }
        if ( ! hash_equals( (string) $analysis['fingerprint'], $expectedFingerprint)) {
            return [
				'ok' => false,
				'reason' => 'changed',
				'provider_id' => 0,
			];
        }
        if ($analysis['already_configured']) {
            return [
				'ok' => false,
				'reason' => 'already_configured',
				'provider_id' => 0,
			];
        }

        $settings = get_option(self::OPTION, []);
        $connection = $settings['connections'][ (string) $analysis['default_id'] ] ?? null;
        if ( ! is_array($connection)) {
            return [
				'ok' => false,
				'reason' => 'missing',
				'provider_id' => 0,
			];
        }

        $sourceType = (string) $analysis['source_type'];
        $connection = $this->decodeConnection($sourceType, $connection);

        $config = $this->mapConfig( (string) $analysis['target_type'], $connection);
        $providerId = $this->providers->save([
            'name' => (string) ($analysis['default_name'] ?: __('Imported SureMail connection', 'onesmtp')),
            'slug' => sanitize_key('suremail_' . (string) $analysis['target_type']),
            'adapter_type' => (string) $analysis['target_type'],
            'priority' => max(1, (int) ($connection['priority'] ?? 100)),
            'weight' => 1,
            'is_active' => 0,
            'config' => $config,
        ]);

        return [
			'ok' => $providerId > 0,
			'reason' => $providerId > 0 ? 'imported' : 'save_failed',
			'provider_id' => $providerId,
		];
    }

    private function findByType(string $type): ?array
    {
        foreach ($this->providers->getAllSafe() as $provider) {
            if (($provider['adapter_type'] ?? '') === $type) {
                return $provider;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function mapConfig(string $type, array $connection): array
    {
        $allowed = [
            'host',
			'port',
			'username',
			'password',
			'encryption',
			'region',
			'api_key',
			'secret_key',
            'domain',
			'client_id',
			'client_secret',
			'refresh_token',
			'access_token',
            'account_id',
			'from_email',
			'from_name',
        ];
        $config = [];
        foreach ($allowed as $field) {
            if (isset($connection[ $field ]) && is_scalar($connection[ $field ])) {
                $config[ $field ] = (string) $connection[ $field ];
            }
        }
        if ($type === ProviderTypes::AMAZON_SES) {
            $config['username'] = (string) ($connection['username'] ?? $connection['access_key'] ?? '');
            $config['password'] = (string) ($connection['password'] ?? $connection['secret_key'] ?? '');
        }
        if ($type === ProviderTypes::POSTMARK && empty($config['api_key'])) {
            $config['api_key'] = (string) ($connection['server_token'] ?? '');
        }
        return array_filter($config, static fn (string $value): bool => $value !== '');
    }

    /** @return array<string,mixed> */
    private function decodeConnection(string $sourceType, array $connection): array
    {
        foreach (self::ENCRYPTED_FIELDS[ $sourceType ] ?? [] as $field) {
            if (isset($connection[ $field ]) && is_string($connection[ $field ])) {
                $connection[ $field ] = $this->decodeSureMailSecret($connection[ $field ]);
            }
        }

        return $connection;
    }

    /** @return list<string> */
    private function missingRequiredFields(string $type, array $config): array
    {
        return array_values(array_filter(
            self::REQUIRED_CONFIG[ $type ] ?? [],
            static fn (string $field): bool => trim( (string) ($config[ $field ] ?? '')) === ''
        ));
    }

    private function decodeSureMailSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $padded = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- SureMail stores credentials as base64 and the value is decoded only in memory before AES-GCM re-encryption.
        $decoded = base64_decode($padded, true);

        return is_string($decoded) ? $decoded : '';
    }
}
