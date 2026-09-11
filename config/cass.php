<?php

declare(strict_types=1);

return [
    'platform_name' => env('CASS_PLATFORM_NAME', 'CASS'),
    'platform_contact_email' => env('CASS_CONTACT_EMAIL', 'cass@towardpcc.com'),
    'admin_email' => env('CASS_ADMIN_EMAIL'),
    'admin_password' => env('CASS_ADMIN_PASSWORD'),
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],
    // Scans counted per client IP per link per minute. The /q redirect itself
    // is never refused (spec 5.7); this only stops a script inflating the
    // counter, so a lecture hall behind one NAT still reaches the page.
    'short_link_rate_limit' => (int) env('CASS_SHORT_LINK_RATE_LIMIT', 60),
    // Second ceiling, per link per minute with no client in the key. The
    // client IP is read from a header the client sets, so the cap above can
    // be minted once per request; this one bounds how fast one link's visit
    // rows can grow whatever address is claimed.
    'short_link_rate_limit_per_link' => (int) env('CASS_SHORT_LINK_RATE_LIMIT_PER_LINK', 600),
    // Visit rows older than this are deleted nightly by `model:prune`. The
    // sharing page reports 30 days, so nothing inside the window is lost.
    'short_link_visit_retention_days' => (int) env('CASS_SHORT_LINK_VISIT_RETENTION_DAYS', 90),
    'qr' => [
        // The PNG is rendered at whole-module scale, so the real width is the
        // smallest multiple of the module count that reaches this size.
        'png_min_size' => (int) env('CASS_QR_PNG_MIN_SIZE', 1024),
    ],
    'countries' => [
        'SA' => 'Saudi Arabia', 'AE' => 'United Arab Emirates', 'BH' => 'Bahrain', 'KW' => 'Kuwait',
        'OM' => 'Oman', 'QA' => 'Qatar', 'EG' => 'Egypt', 'JO' => 'Jordan', 'LB' => 'Lebanon',
        'IQ' => 'Iraq', 'MA' => 'Morocco', 'TN' => 'Tunisia', 'DZ' => 'Algeria', 'SD' => 'Sudan',
        'YE' => 'Yemen', 'SY' => 'Syria', 'PS' => 'Palestine', 'LY' => 'Libya', 'PK' => 'Pakistan',
        'IN' => 'India', 'TR' => 'Türkiye', 'GB' => 'United Kingdom', 'US' => 'United States',
        'CA' => 'Canada', 'AU' => 'Australia', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy',
        'ES' => 'Spain', 'NL' => 'Netherlands', 'IE' => 'Ireland', 'MY' => 'Malaysia', 'ID' => 'Indonesia',
        'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya', 'BR' => 'Brazil', 'MX' => 'Mexico',
        'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China', 'SG' => 'Singapore', 'OTHER' => 'Other',
    ],
];
