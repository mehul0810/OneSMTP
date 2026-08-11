<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

use OneSMTP\Providers\ProviderTypes;

/**
 * Provider-owned OAuth endpoints and the narrow scopes used by this plugin.
 * The descriptor contains no customer credential or token material.
 */
final class ProviderOAuthDescriptor
{
    public const GMAIL_SCOPE = 'https://www.googleapis.com/auth/gmail.send';
    public const ZOHO_SCOPE = 'ZohoMail.messages.CREATE';

    /** @var array<string,string> */
    private const ZOHO_REGIONS = [
        'com' => 'com',
        'in' => 'in',
        'eu' => 'eu',
        'com.au' => 'com.au',
        'jp' => 'jp',
        'ca' => 'zohocloud.ca',
        'com.cn' => 'com.cn',
    ];

    private function __construct(
        private string $providerType,
        private string $authorizationEndpoint,
        private string $tokenEndpoint,
        private string $revokeEndpoint,
        private string $scope,
        private bool $usesPkce
    ) {
    }

    public static function forProvider(string $providerType, string $region = 'com'): ?self
    {
        $providerType = strtolower(trim($providerType));
        if ($providerType === ProviderTypes::GMAIL) {
            return new self(
                ProviderTypes::GMAIL,
                'https://accounts.google.com/o/oauth2/v2/auth',
                'https://oauth2.googleapis.com/token',
                'https://oauth2.googleapis.com/revoke',
                self::GMAIL_SCOPE,
                false
            );
        }

        if ($providerType !== ProviderTypes::ZOHO_MAIL) {
            return null;
        }

        $region = self::normalizeRegion($region);
        $host = $region === 'ca'
            ? 'accounts.zohocloud.ca'
            : 'accounts.zoho.' . self::ZOHO_REGIONS[ $region ];

        return new self(
            ProviderTypes::ZOHO_MAIL,
            'https://' . $host . '/oauth/v2/auth',
            'https://' . $host . '/oauth/v2/token',
            'https://' . $host . '/oauth/v2/token/revoke',
            self::ZOHO_SCOPE,
            true
        );
    }

    public static function normalizeRegion(string $region): string
    {
        $region = strtolower(trim($region));

        return isset(self::ZOHO_REGIONS[ $region ]) ? $region : 'com';
    }

    public function getProviderType(): string
    {
        return $this->providerType;
    }

    public function getAuthorizationEndpoint(): string
    {
        return $this->authorizationEndpoint;
    }

    public function getTokenEndpoint(): string
    {
        return $this->tokenEndpoint;
    }

    public function getRevokeEndpoint(): string
    {
        return $this->revokeEndpoint;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function usesPkce(): bool
    {
        return $this->usesPkce;
    }
}
