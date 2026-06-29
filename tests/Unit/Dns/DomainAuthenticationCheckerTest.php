<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Dns;

use OneSMTP\Dns\DnsResolverInterface;
use OneSMTP\Dns\DomainAuthenticationChecker;
use PHPUnit\Framework\TestCase;

final class DomainAuthenticationCheckerTest extends TestCase
{
    public function test_check_reports_present_records_only_when_matching_txt_evidence_exists(): void
    {
        $checker = new DomainAuthenticationChecker(
            new StaticDnsResolver(
                true,
                [
                    'example.test' => ['v=spf1 include:_spf.example.test -all'],
                    'mail._domainkey.example.test' => ['v=DKIM1; k=rsa; p=synthetic'],
                    '_dmarc.example.test' => ['v=DMARC1; p=quarantine'],
                ]
            )
        );

        $result = $checker->check('Example.Test', 'mail');

        self::assertSame(DomainAuthenticationChecker::STATUS_PRESENT, $result['spf']['status']);
        self::assertSame(DomainAuthenticationChecker::STATUS_PRESENT, $result['dkim']['status']);
        self::assertSame(DomainAuthenticationChecker::STATUS_PRESENT, $result['dmarc']['status']);
    }

    public function test_check_reports_missing_records_without_matching_txt_evidence(): void
    {
        $checker = new DomainAuthenticationChecker(
            new StaticDnsResolver(
                true,
                [
                    'example.test' => ['not an spf record'],
                    'mail._domainkey.example.test' => ['not a dkim record'],
                    '_dmarc.example.test' => ['not a dmarc record'],
                ]
            )
        );

        $result = $checker->check('example.test', 'mail');

        self::assertSame(DomainAuthenticationChecker::STATUS_MISSING, $result['spf']['status']);
        self::assertSame(DomainAuthenticationChecker::STATUS_MISSING, $result['dkim']['status']);
        self::assertSame(DomainAuthenticationChecker::STATUS_MISSING, $result['dmarc']['status']);
    }

    public function test_check_reports_inconclusive_when_lookup_is_unavailable_or_selector_is_missing(): void
    {
        $checker = new DomainAuthenticationChecker(new StaticDnsResolver(false, []));

        $result = $checker->check('example.test', '');

        self::assertSame(DomainAuthenticationChecker::STATUS_INCONCLUSIVE, $result['spf']['status']);
        self::assertSame(DomainAuthenticationChecker::STATUS_INCONCLUSIVE, $result['dkim']['status']);
        self::assertSame(DomainAuthenticationChecker::STATUS_INCONCLUSIVE, $result['dmarc']['status']);
        self::assertSame('', $result['dkim']['query']);
    }
}

final class StaticDnsResolver implements DnsResolverInterface
{
    /**
     * @param array<string,array<int,string>> $records
     */
    public function __construct(private bool $available, private array $records)
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function txt(string $domain): array
    {
        return $this->records[$domain] ?? [];
    }
}
