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
