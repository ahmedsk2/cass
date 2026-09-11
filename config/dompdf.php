<?php

declare(strict_types=1);

return [
    /*
     * CASS renders exactly one document with dompdf: the conference poster.
     * It contains no user-supplied URLs - the organization logo and the QR
     * code are inlined as data: URIs by GenerateConferencePoster - so remote
     * fetching, PHP and JavaScript are all switched off. The only thing
     * dompdf reads from disk is the three bundled IBM Plex TTFs, and
     * those are registered programmatically by App\Support\Pdf\PosterFonts
     * rather than through an @font-face URL, because a Windows
     * "file://C:\..." URL is not portable.
     */
    'show_warnings' => false,
    'public_path' => null,
    'convert_entities' => true,

    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),
        'chroot' => realpath(base_path()),
        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
        ],
        'enable_font_subsetting' => false,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'print',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'sans-serif',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => false,
        'allowed_remote_hosts' => null,
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],
];
