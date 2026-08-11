<?php

declare(strict_types=1);

namespace OneSMTP\Product\Licensing;

interface LicenseClient
{
    public function status(): LicenseStatus;

    public function activate(string $licenseKey): LicenseStatus;

    public function deactivate(): LicenseStatus;

    public function refresh(): LicenseStatus;
}
