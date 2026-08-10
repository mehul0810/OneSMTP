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
        if ($providerType === ProviderTypes::ZOHO_MAIL) {
            return $this->evaluateZoho($context);
        }

        // GmailAdapter is SMTP-backed today; do not claim OAuth lifecycle support.
        if ($providerType === ProviderTypes::GMAIL) {
            return ProviderAuthStatus::forState(ProviderAuthState::UNSUPPORTED);
        }

        return ProviderAuthStatus::forState(
            in_array($providerType, self::STATIC_PROVIDERS, true)
                ? ProviderAuthState::STATIC
                : ProviderAuthState::UNKNOWN
        );
    }

    private function evaluateZoho(ProviderAuthContext $context): ProviderAuthStatus
    {
        $configuration = $context->getConfiguration();
        $refreshResult = $context->getRefreshResult();

        if ($refreshResult === null) {
            if ($configuration->hasRefreshCredentials()) {
                return ProviderAuthStatus::forState(ProviderAuthState::CONNECTED);
            }

            return ProviderAuthStatus::forState(
                $configuration->hasPartialRefreshCredentials()
                    ? ProviderAuthState::REAUTH_REQUIRED
                    : ProviderAuthState::DISCONNECTED
            );
        }

        return match ($refreshResult->getState()) {
            ProviderAuthRefreshState::SUCCESS => $configuration->hasRefreshCredentials()
                ? ProviderAuthStatus::forState(ProviderAuthState::CONNECTED)
                : ProviderAuthStatus::forState(ProviderAuthState::REAUTH_REQUIRED),
            ProviderAuthRefreshState::NETWORK_ERROR => ProviderAuthStatus::forState(ProviderAuthState::REFRESH_FAILED),
            ProviderAuthRefreshState::INVALID_CREDENTIALS => ProviderAuthStatus::forState(ProviderAuthState::REAUTH_REQUIRED),
            ProviderAuthRefreshState::REVOKED => ProviderAuthStatus::forState(ProviderAuthState::REVOKED),
            ProviderAuthRefreshState::UNKNOWN => ProviderAuthStatus::forState(ProviderAuthState::UNKNOWN),
        };
    }
}
