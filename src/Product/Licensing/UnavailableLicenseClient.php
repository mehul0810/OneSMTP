<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

final class UnavailableLicenseClient implements LicenseClient
{
    public function status(): LicenseStatus
    {
        return LicenseStatus::unavailable();
    }

    public function activate(string $licenseKey): LicenseStatus
    {
        return LicenseStatus::unavailable();
    }

    public function deactivate(): LicenseStatus
    {
        return LicenseStatus::unavailable();
    }

    public function refresh(): LicenseStatus
    {
        return LicenseStatus::unavailable();
    }
}
