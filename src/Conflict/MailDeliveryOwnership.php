<?php

declare(strict_types=1);

namespace OneSMTP\Conflict;

/**
 * Reports whether Aculect Mail can intercept the canonical WordPress mail path.
 *
 * SureMail can replace wp_mail() before WordPress loads its pluggable default.
 * That replacement does not execute pre_wp_mail, so Aculect Mail must not claim
 * delivery readiness while the replacement owns the function.
 */
final class MailDeliveryOwnership
{
    public const ACULECT = 'aculect_mail';
    public const SUREMAIL = 'suremail';

    public function __construct(private ?string $forcedOwner = null)
    {
    }

    public function owner(): string
    {
        if ($this->forcedOwner !== null) {
            return $this->forcedOwner;
        }
        if ( ! function_exists('wp_mail') || ! defined('SUREMAILS_FILE')) {
            return self::ACULECT;
        }

        try {
            $function = new \ReflectionFunction('wp_mail');
            $source = $function->getFileName();
        } catch (\ReflectionException $e) {
            return self::ACULECT;
        }

        $sureMailFile = realpath( (string) constant('SUREMAILS_FILE'));
        $sourceFile = is_string($source) ? realpath($source) : false;

        return $sureMailFile !== false && $sourceFile === $sureMailFile
            ? self::SUREMAIL
            : self::ACULECT;
    }

    public function canAculectDeliver(): bool
    {
        return $this->owner() === self::ACULECT;
    }
}
