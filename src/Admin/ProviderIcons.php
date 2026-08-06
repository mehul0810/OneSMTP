<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

/**
 * Local provider marks used in the provider catalog.
 *
 * The marks remain bundled so the admin screen does not depend on a remote
 * image host. They use the providers' established colours and recognisable
 * product marks; generic SMTP uses an intentionally neutral mail mark.
 * Provider names and marks remain the property of their respective owners.
 */
final class ProviderIcons
{
    /**
     * @var array<string,array{view_box:string,body:string}>
     */
    private const ICONS = [
        'smtp' => [
            'view_box' => '0 0 24 24',
            'body' => '<rect x="2.5" y="4.5" width="19" height="15" rx="2.5" fill="#52627B"/><path d="m4.4 7 7.6 5.6L19.6 7" fill="none" stroke="#fff" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"/>',
        ],
        'amazon_ses' => [
            'view_box' => '0 0 256 256',
            'body' => '<defs><linearGradient id="onesmtp-aws-ses-gradient" x1="0%" x2="100%" y1="100%" y2="0%"><stop offset="0%" stop-color="#BD0816"/><stop offset="100%" stop-color="#FF5252"/></linearGradient></defs><path fill="url(#onesmtp-aws-ses-gradient)" d="M0 0h256v256H0z"/><path fill="#fff" d="M182.4 195.2c0-5.204-4.397-9.6-9.6-9.6s-9.6 4.396-9.6 9.6s4.397 9.6 9.6 9.6s9.6-4.397 9.6-9.6M128 192c-5.203 0-9.6 4.396-9.6 9.6s4.397 9.6 9.6 9.6s9.6-4.397 9.6-9.6c0-5.204-4.397-9.6-9.6-9.6m-44.8-6.4c-5.203 0-9.6 4.396-9.6 9.6s4.397 9.6 9.6 9.6s9.6-4.397 9.6-9.6m8.336-48.001h72.928l-24.5-22.046l-9.887 8.474a3.18 3.18 0 0 1-2.08.771a3.18 3.18 0 0 1-2.08-.77l-9.885-8.475zM86.4 90.155v43.46l24.733-22.26zm77.75-3.757h-72.3l36.147 30.986zm5.45 47.217v-43.46l-24.733 21.197zm19.2 61.585c0 8.672-7.328 16-16 16s-16-7.328-16-16c0-7.581 5.6-14.132 12.8-15.661v-9.94h-38.4v16.34c7.2 1.53 12.8 8.08 12.8 15.66c0 8.673-7.328 16.001-16 16.001s-16-7.328-16-16c0-7.581 5.6-14.132 12.8-15.661v-16.34H86.4v9.94c7.2 1.53 12.8 8.08 12.8 15.66c0 8.673-7.328 16.001-16 16s-16-7.327-16-16c0-7.58 5.6-14.13 12.8-15.66v-13.14a3.2 3.2 0 0 1 3.2-3.2h41.6v-19.2H83.2a3.2 3.2 0 0 1-3.2-3.2V83.198a3.2 3.2 0 0 1 3.2-3.2h89.6a3.2 3.2 0 0 1 3.2 3.2v57.6a3.2 3.2 0 0 1-3.2 3.2h-41.6V163.2h41.6a3.2 3.2 0 0 1 3.2 3.2v13.14c7.2 1.53 12.8 8.08 12.8 15.66m28.8-67.202c0 18.903-5.834 36.993-16.874 52.305l-5.193-3.744c10.25-14.218 15.667-31.008 15.667-48.56c0-45.873-37.322-83.199-83.197-83.199c-45.878 0-83.203 37.326-83.203 83.198c0 17.553 5.418 34.343 15.667 48.561l-5.193 3.744C44.234 164.991 38.4 146.901 38.4 127.998c0-49.402 40.195-89.598 89.597-89.598c49.405 0 89.603 40.196 89.603 89.598"/>',
        ],
        'php_mail' => [
            'view_box' => '0 0 24 24',
            'body' => '<ellipse cx="12" cy="12" rx="11" ry="5.8" fill="#777BB4"/><text x="4.6" y="14.15" fill="#fff" font-family="Arial,sans-serif" font-size="5" font-weight="700">PHP</text>',
        ],
        'gmail' => [
            'view_box' => '0 0 256 193',
            'body' => '<path fill="#4285F4" d="M58.182 192.05V93.14L27.507 65.077L0 49.504v125.091c0 9.658 7.825 17.455 17.455 17.455z"/><path fill="#34A853" d="M197.818 192.05h40.727c9.659 0 17.455-7.826 17.455-17.455V49.505l-31.156 17.837l-27.026 25.798z"/><path fill="#EA4335" d="m58.182 93.14-4.174-38.647l4.174-36.989L128 69.868l69.818-52.364 4.669 34.992-4.669 40.644L128 145.504z"/><path fill="#FBBC04" d="M197.818 17.504V93.14L256 49.504V26.231c0-21.585-24.64-33.89-41.89-20.945z"/><path fill="#C5221F" d="m0 49.504 26.759 20.07L58.182 93.14V17.504L41.89 5.286C24.61-7.66 0 4.646 0 26.23z"/>',
        ],
        'sendgrid' => [
            'view_box' => '0 0 256 256',
            'body' => '<path fill="#9DD6E3" d="M256 0v170.667h-85.333v85.33H.002v-85.331H0V85.332h85.333V0z"/><path fill="#3F72AB" d="M.002 255.996h85.333v-85.333H.002z"/><path fill="#00A9D1" d="M170.667 170.667H256V85.331h-85.333zM85.333 85.333h85.334V0H85.333z"/><path fill="#2191C4" d="M85.333 170.665h85.334V85.331H85.333z"/><path fill="#3F72AB" d="M170.667 85.333H256V0h-85.333z"/>',
        ],
        'postmark' => [
            'view_box' => '0 0 24 24',
            'body' => '<circle cx="12" cy="12" r="10" fill="#FFBE0B"/><path fill="#fff" d="M8 5.5h4.1c2.98 0 4.9 1.55 4.9 4.15 0 2.66-1.92 4.18-4.9 4.18h-1.55V18H8zm2.55 2.4v3.62h1.28c1.56 0 2.48-.58 2.48-1.83 0-1.23-.92-1.79-2.48-1.79z"/>',
        ],
        'brevo' => [
            'view_box' => '0 0 24 24',
            'body' => '<path fill="#0B996E" d="M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zM7.2 4.8h5.747c2.34 0 3.895 1.406 3.895 3.516 0 1.022-.348 1.862-1.09 2.588C17.189 11.812 18 13.22 18 14.785c0 2.86-2.64 5.016-6.164 5.016H7.199v-15zm2.085 1.952v5.537h.07c.233-.432.858-.796 2.249-1.226 2.039-.659 3.037-1.52 3.037-2.655 0-.998-.766-1.656-1.924-1.656H9.285zm4.87 5.266c-.766.385-1.67.748-2.76 1.11-1.229.387-2.11 1.386-2.11 2.407v2.315h2.365c2.387 0 4.149-1.34 4.149-3.155 0-1.067-.625-2.087-1.645-2.677z"/>',
        ],
        'mailgun' => [
            'view_box' => '0 0 256 261',
            'body' => '<path fill="#F06B66" d="M126.143.048C197.685.048 256 58.363 256 130.025a42.083 42.083 0 0 1-63.967 35.71l-.6-.36-.241.601c-18.108 32.825-57.803 47.059-92.643 33.22-34.84-13.837-53.951-51.428-44.602-87.731 9.349-36.304 44.24-59.988 81.43-55.276s65.073 36.348 65.073 73.836a13.707 13.707 0 0 0 27.294 0c0-56.132-45.469-101.655-101.601-101.721-47.083-.085-88.07 32.152-99.083 77.93-11.012 45.776 10.83 93.128 52.8 114.466s93.098 11.085 123.596-24.784l21.643 18.276a129.5 129.5 0 0 1-98.956 45.93C55.864 257.986 0 200.397 0 130.086S55.864 2.185 126.143.048m0 83.926a46.171 46.171 0 1 0 .12 92.223c24.551-1.286 43.789-21.584 43.757-46.169s-19.323-44.832-43.877-46.054m0 27.414c10.293 0 18.637 8.344 18.637 18.637s-8.344 18.637-18.637 18.637-18.637-8.344-18.637-18.637 8.344-18.637 18.637-18.637"/>',
        ],
        'resend' => [
            'view_box' => '0 0 24 24',
            'body' => '<path fill="#111827" d="M14.679 0c4.648 0 7.413 2.765 7.413 6.434s-2.765 6.434-7.413 6.434H12.33L24 24h-8.245l-8.88-8.44c-.636-.588-.93-1.273-.93-1.86 0-.831.587-1.565 1.713-1.883l4.574-1.224c1.737-.465 2.936-1.81 2.936-3.572 0-2.153-1.761-3.4-3.939-3.4H0V0z"/>',
        ],
        'mailjet' => [
            'view_box' => '0 0 256 255',
            'body' => '<path fill="#9585F4" d="m0 97.991 93.408 42.34 18.769-18.66-47.795-21.715 148.187-56.744-56.961 147.533-21.606-47.359-18.878 18.769.982 2.183 41.357 90.68L256 0z"/>',
        ],
        'sparkpost' => [
            'view_box' => '0 0 24 24',
            'body' => '<path fill="#FA6423" d="M16.2 9c-1.351.9-1.8 2.7-1.65 3.9-2.25-2.25 3.45-8.55-3-12.9C15.15 5.4 6 9.75 6 17.4c0 3 1.95 5.701 6 6.6 4.05-.898 6-3.6 6-6.6 0-4.5-2.7-6-1.8-8.4zM12 20.852c-1.8 0-3.45-1.5-3.45-3.451 0-1.801 1.5-3.45 3.45-3.45 1.8 0 3.45 1.5 3.45 3.45-.15 1.951-1.65 3.451-3.45 3.451z"/>',
        ],
        'mailersend' => [
            'view_box' => '0 0 24 24',
            'body' => '<path fill="#00A7B5" d="m2.5 8.35 8.65-4.33v8.65L2.5 17z"/><path fill="#635BFF" d="m12.85 4.02 8.65 4.33V17l-8.65-4.33z"/><path fill="#00C2A8" d="m2.5 8.35 8.65 4.32v7.31L2.5 15.65z"/>',
        ],
        'smtp2go' => [
            'view_box' => '0 0 24 24',
            'body' => '<text x="1" y="15.4" fill="#1F2937" font-family="Arial,sans-serif" font-size="5.2" font-weight="700">SMTP</text><text x="13.05" y="15.4" fill="#32B6E7" font-family="Arial,sans-serif" font-size="5.2" font-weight="700">2</text><text x="16.1" y="15.4" fill="#1F2937" font-family="Arial,sans-serif" font-size="5.2" font-weight="700">GO</text>',
        ],
        'elastic_email' => [
            'view_box' => '0 0 24 24',
            'body' => '<circle cx="12" cy="12" r="10" fill="#5B5BD6"/><path d="M7.1 8.1h9.8v2.15h-7.1v1.25h5.7v2.05H9.8v1.3h7.1V17H7.1z" fill="#fff"/>',
        ],
        'zeptomail' => [
            'view_box' => '0 0 24 24',
            'body' => '<circle cx="12" cy="12" r="10" fill="#2E8BC0"/><path d="M6.25 8h11.5v8h-11.5z" fill="#F9B21D"/><path d="m6.8 8.55 5.2 3.85 5.2-3.85" fill="none" stroke="#2E8BC0" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.25"/><path d="M18.3 5.5a7.35 7.35 0 0 1 1.07 4" fill="none" stroke="#F9B21D" stroke-linecap="round" stroke-width="1.25"/>',
        ],
        'mailchimp_transactional' => [
            'view_box' => '0 0 24 24',
            'body' => '<circle cx="12" cy="12" r="10" fill="#FFE01B"/><path fill="#241C15" d="M7.05 9.3c-1.1-1.12-2.86-.38-2.56 1.22.16.9.97 1.42 1.84 1.3.27 3.34 2.64 5.65 5.67 5.65 2.82 0 5.04-2 5.55-5.03.95.04 1.82-.63 1.9-1.59.13-1.6-1.7-2.12-2.66-1.11-.62-2.17-2.4-3.7-4.79-3.7-2.21 0-4.22 1.42-4.95 3.27m2.22 3.12a.82.82 0 1 1 .01-1.64.82.82 0 0 1-.01 1.64m5.46 0a.82.82 0 1 1 .01-1.64.82.82 0 0 1-.01 1.64m-4.1 2.11c.87.55 1.87.55 2.74 0l.41.78c-1.12.73-2.44.73-3.56 0z"/>',
        ],
        'zoho_mail' => [
            'view_box' => '0 0 24 24',
            'body' => '<rect x="2" y="4" width="20" height="16" rx="3" fill="#E42527"/><path d="M5 8.2 12 13l7-4.8" fill="none" stroke="#fff" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/><text x="7.1" y="18.2" fill="#fff" font-family="Arial,sans-serif" font-size="4" font-weight="700">ZOHO</text>',
        ],
        'emailit' => [
            'view_box' => '0 0 24 24',
            'body' => '<rect x="2" y="2" width="20" height="20" rx="5" fill="#4F46E5"/><path d="M6 8h12v8H6z" fill="none" stroke="#fff" stroke-width="1.7"/><path d="m6.8 9 5.2 3.7L17.2 9" fill="none" stroke="#fff" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/>',
        ],
        'netcore' => [
            'view_box' => '0 0 24 24',
            'body' => '<circle cx="12" cy="12" r="10" fill="#6A36C9"/><path d="M7 17V7h2.4l5.2 6.1V7H17v10h-2.3L9.4 10.9V17z" fill="#fff"/>',
        ],
    ];

    public static function render(string $type): string
    {
        $icon = self::ICONS[ $type ] ?? self::ICONS['smtp'];

        return '<svg class="onesmtp-provider-logo onesmtp-provider-logo-' . esc_attr($type) . '" xmlns="http://www.w3.org/2000/svg" viewBox="' . esc_attr($icon['view_box']) . '" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">' . $icon['body'] . '</svg>';
    }
}
