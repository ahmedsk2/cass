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
        // Derived from the same environment variable config/cass.php reads,
        // and not written out: SubmissionForm::uploadRules() takes its cap from
        // cass.max_file_bytes, so a hard-coded number here means raising
        // CASS_MAX_FILE_BYTES moves one knob and leaves this endpoint refusing
        // at the old one with nothing to say the two disagree. The env() call
        // is repeated rather than shared because a config file cannot call
        // config() - nothing is loaded yet - so the default must match
        // config/cass.php's exactly.
        'rules' => ['required', 'file', 'max:'.(int) floor(((int) env('CASS_MAX_FILE_BYTES', 10 * 1024 * 1024)) / 1024)],
        'directory' => null,
        // Cast and floored at 1. Concatenating env() straight in meant an empty
        // `CASS_UPLOAD_RATE_LIMIT=` line - which is how a variable gets
        // disabled - produced `throttle:,1`, and ThrottleRequests reads the
        // missing count as zero attempts: a 429 on every upload from the public
        // form, with the cause sitting in a .env file nobody would think to
        // look at.
        'middleware' => 'throttle:'.max(1, (int) env('CASS_UPLOAD_RATE_LIMIT', 20)).',1',
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
