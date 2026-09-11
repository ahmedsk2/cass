<?php

declare(strict_types=1);

/*
 * Only the temporary-upload block is overridden; everything else falls through
 * to vendor/livewire/livewire/config/livewire.php via mergeConfigFrom. Because
 * that merge is shallow, this array has to be COMPLETE - a partial one replaces
 * the package's whole block.
 *
 * This endpoint accepts bytes from anonymous visitors on
 * /c/{org}/{conf}/submit, so it carries the same 10 MB cap as the form. No
 * `extensions` rule: the SAME endpoint serves the organizer's
 * FileUpload::make('logo_path') in EditOrganizationProfile, and a pdf-only rule
 * there would refuse every logo. Type is enforced where it belongs, in
 * SubmissionForm::uploadRules() and StoreSubmissionFile's content sniff.
 */
return [
    'temporary_file_upload' => [
        // null, so it follows filesystems.default (local -> storage/app/private,
        // inside the cass-storage volume).
        'disk' => null,
        'rules' => ['required', 'file', 'max:10240'],
        'directory' => null,
        'middleware' => 'throttle:'.env('CASS_UPLOAD_RATE_LIMIT', 20).',1',
        // The package's list, unchanged: Filament previews an uploaded logo
        // through livewire.preview-file, which checks it.
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
