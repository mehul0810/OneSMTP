<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Conflict;

use OneSMTP\Conflict\MailDeliveryOwnership;
use PHPUnit\Framework\TestCase;

final class MailDeliveryOwnershipTest extends TestCase
{
    public function test_suremail_owner_blocks_aculect_delivery_claim(): void
    {
        $ownership = new MailDeliveryOwnership(MailDeliveryOwnership::SUREMAIL);

        self::assertSame(MailDeliveryOwnership::SUREMAIL, $ownership->owner());
        self::assertFalse($ownership->canAculectDeliver());
    }

    public function test_aculect_owner_allows_delivery(): void
    {
        self::assertTrue((new MailDeliveryOwnership(MailDeliveryOwnership::ACULECT))->canAculectDeliver());
    }
}
