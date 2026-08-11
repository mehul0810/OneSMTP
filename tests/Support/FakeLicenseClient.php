<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Support;

use OneSMTP\Product\Licensing\LicenseClient;
use OneSMTP\Product\Licensing\LicenseStatus;

final class FakeLicenseClient implements LicenseClient
{
    public function __construct(private LicenseStatus $result)
    {
    }

    public function status(): LicenseStatus
    {
        return $this->result;
    }

    public function activate(string $licenseKey): LicenseStatus
    {
        return $this->result;
    }

    public function deactivate(): LicenseStatus
    {
        return $this->result;
    }

    public function refresh(): LicenseStatus
    {
        return $this->result;
    }
}
