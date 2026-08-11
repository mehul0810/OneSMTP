<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Auth;

use OneSMTP\Providers\ProviderTypes;

/**
 * Pure mapper for the current provider configuration and refresh contracts.
 *
 * It deliberately does not perform network calls, persistence, token actions,
 * callbacks, or UI wiring. Unknown provider/auth evidence fails closed.
 */
final class ProviderAuthEvaluator implements ProviderAuthLifecycleEvaluatorInterface
{
    /** @var array<int,string> */
    private const STATIC_PROVIDERS = [
        ProviderTypes::SMTP,
        ProviderTypes::AMAZON_SES,
        ProviderTypes::PHP_MAIL,
        ProviderTypes::SENDGRID,
        ProviderTypes::POSTMARK,
        ProviderTypes::BREVO,
        ProviderTypes::MAILGUN,
        ProviderTypes::RESEND,
        ProviderTypes::MAILJET,
        ProviderTypes::SPARKPOST,
        ProviderTypes::MAILERSEND,
        ProviderTypes::SMTP2GO,
        ProviderTypes::ELASTIC_EMAIL,
        ProviderTypes::ZEPTOMAIL,
        ProviderTypes::MAILCHIMP_TRANSACTIONAL,
        ProviderTypes::EMAILIT,
        ProviderTypes::NETCORE,
    ];

    public function evaluate(ProviderAuthContext $context): ProviderAuthStatus
    {
        $providerType = $context->getProviderType();
        if (in_array($providerType, [ ProviderTypes::ZOHO_MAIL, ProviderTypes::GMAIL ], true)) {
            return $this->evaluateOAuth($context);
        }

        return $this->status(
            in_array($providerType, self::STATIC_PROVIDERS, true)
                ? ProviderAuthState::STATIC
                : ProviderAuthState::UNKNOWN
        );
    }

    private function evaluateOAuth(ProviderAuthContext $context): ProviderAuthStatus
    {
        $configuration = $context->getConfiguration();
        $refreshResult = $context->getRefreshResult();

        if ($refreshResult === null) {
            if ($configuration->hasRefreshCredentials()) {
                return $this->status(ProviderAuthState::CONFIGURED_UNVERIFIED, true);
            }

            return $this->status(
                $configuration->hasPartialRefreshCredentials()
                    ? ProviderAuthState::REAUTH_REQUIRED
                    : ProviderAuthState::DISCONNECTED,
                true
            );
        }

        return match ($refreshResult->getState()) {
            ProviderAuthRefreshState::SUCCESS => $configuration->hasRefreshCredentials()
                ? $this->connectedStatus($context)
                : $this->status(ProviderAuthState::REAUTH_REQUIRED, true),
            ProviderAuthRefreshState::NETWORK_ERROR => $this->status(ProviderAuthState::REFRESH_FAILED, true),
            ProviderAuthRefreshState::INVALID_CREDENTIALS => $this->status(ProviderAuthState::REAUTH_REQUIRED, true),
            ProviderAuthRefreshState::REVOKED => $this->status(ProviderAuthState::REVOKED, true),
            ProviderAuthRefreshState::UNKNOWN => $this->status(ProviderAuthState::UNKNOWN),
        };
    }

    private function connectedStatus(ProviderAuthContext $context): ProviderAuthStatus
    {
        $revocationEvidence = $context->getRevocationEvidence();
        $capabilities = $revocationEvidence !== null && $revocationEvidence->allowsRevocation()
            ? ProviderAuthCapabilities::reconnectAndRevoke()
            : ProviderAuthCapabilities::reconnectOnly();

        return ProviderAuthStatus::forState(ProviderAuthState::CONNECTED, $capabilities);
    }

    private function status(ProviderAuthState $state, bool $canReconnect = false): ProviderAuthStatus
    {
        return ProviderAuthStatus::forState(
            $state,
            $canReconnect ? ProviderAuthCapabilities::reconnectOnly() : ProviderAuthCapabilities::none()
        );
    }
}
