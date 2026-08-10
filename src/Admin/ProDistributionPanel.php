<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

use OneSMTP\Product\Licensing\LicenseClient;
use OneSMTP\Product\Licensing\LicenseState;
use OneSMTP\Product\Licensing\LicenseStatus;
use OneSMTP\Product\Licensing\UnavailableLicenseClient;
use OneSMTP\Product\Licensing\UnavailableUpdateProvider;
use OneSMTP\Product\Licensing\UpdateProvider;
use OneSMTP\Product\Licensing\UpdateState;
use OneSMTP\Product\Licensing\UpdateStatus;
use Throwable;

final class ProDistributionPanel
{
    public function __construct(
        private ?LicenseClient $licenses = null,
        private ?UpdateProvider $updates = null
    ) {
        $this->licenses ??= new UnavailableLicenseClient();
        $this->updates ??= new UnavailableUpdateProvider();
    }

    public function render(): void
    {
        $license = $this->licenseStatus();
        $update = $this->updateStatus();

        echo '<section class="onesmtp-settings-panel onesmtp-settings-panel--full onesmtp-pro-distribution postbox">';
        echo '<div class="postbox-header"><h3 class="hndle">' . esc_html__('Pro distribution foundation', 'onesmtp') . '</h3></div>';
        echo '<div class="inside">';
        echo '<p class="description onesmtp-settings-panel-description">' . esc_html__('Aculect Mail 0.4.0 includes local integration contracts only. No license service, activation request, update feed, package download, or purchase flow is connected.', 'onesmtp') . '</p>';
        echo '<dl class="onesmtp-pro-distribution-status">';
        $this->renderStatus(__('License service', 'onesmtp'), $this->licenseLabel($license), $license->maskedIdentifier());
        $this->renderStatus(__('Pro updates', 'onesmtp'), $this->updateLabel($update));
        echo '</dl>';
        echo '<p class="description">' . esc_html__('Pro features remain denied unless a separately reviewed runtime supplies both entitlement evidence and the feature rollout flag.', 'onesmtp') . '</p>';
        echo '</div></section>';
    }

    private function licenseStatus(): LicenseStatus
    {
        try {
            return $this->licenses?->status() ?? LicenseStatus::unavailable();
        } catch (Throwable) {
            return LicenseStatus::create(LicenseState::ERROR, reason: 'service_error');
        }
    }

    private function updateStatus(): UpdateStatus
    {
        try {
            return $this->updates?->status() ?? UpdateStatus::unavailable();
        } catch (Throwable) {
            return UpdateStatus::create(UpdateState::ERROR, 'service_error');
        }
    }

    private function licenseLabel(LicenseStatus $status): string
    {
        return match ($status->state()) {
            LicenseState::UNAVAILABLE => __('Not configured', 'onesmtp'),
            LicenseState::INACTIVE => __('Inactive', 'onesmtp'),
            LicenseState::ACTIVE => __('Active', 'onesmtp'),
            LicenseState::INVALID => __('Invalid', 'onesmtp'),
            LicenseState::EXPIRED => __('Expired', 'onesmtp'),
            LicenseState::ERROR => __('Status unavailable', 'onesmtp'),
        };
    }

    private function updateLabel(UpdateStatus $status): string
    {
        return match ($status->state()) {
            UpdateState::UNAVAILABLE => __('Not configured', 'onesmtp'),
            UpdateState::CURRENT => __('Current', 'onesmtp'),
            UpdateState::UPDATE_AVAILABLE => __('Update available', 'onesmtp'),
            UpdateState::ERROR => __('Status unavailable', 'onesmtp'),
        };
    }

    private function renderStatus(string $label, string $value, string $identifier = ''): void
    {
        echo '<div class="onesmtp-pro-distribution-row"><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value);
        if ($identifier !== '') {
            echo ' <code>' . esc_html($identifier) . '</code>';
        }
        echo '</dd></div>';
    }
}
