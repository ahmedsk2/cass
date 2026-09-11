<?php

declare(strict_types=1);

namespace App\Support\Files;

use finfo;

/**
 * Spec section 8: uploads are validated by "extension and sniffed MIME". Both,
 * and they have to agree - an extension alone is a claim the uploader makes,
 * and a sniffed type alone would let `payload.exe` through as long as its
 * bytes looked like a PDF to something downstream.
 *
 * `fileinfo` is loaded on this machine and compiled into php:8.4-fpm-alpine, so
 * this is finfo and not exif (which is absent here) and not
 * UploadedFile::getMimeType() (which asks the *client's* Content-Type first on
 * some paths).
 */
final class SniffedMimeType
{
    /**
     * Extension to the MIME types a truthful file of that extension may sniff
     * as. The keys are exactly the options of the `allowed_file_types`
     * CheckboxList in ConferenceForm; a conference cannot offer anything else.
     *
     * @var array<string, list<string>>
     */
    public const EXPECTED = [
        'pdf' => ['application/pdf'],
        // An OLE2 compound document. Older magic databases stop at the
        // container and answer application/CDFV2 or application/vnd.ms-office.
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2'],
        // A .docx is a zip. Most magic databases recognise the OOXML marker
        // inside it; the ones that do not answer application/zip, and refusing
        // every author on such a host would be worse than accepting a zip whose
        // name ends in .docx and which nothing on this platform ever executes.
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
    ];

    /** @return list<string> */
    public static function allowedExtensions(): array
    {
        return array_keys(self::EXPECTED);
    }

    public static function forPath(string $path): ?string
    {
        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            return null;
        }

        try {
            return self::forStream($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Reads the head of the stream and rewinds it, because the caller
     * (StoreSubmissionFile) hashes and copies the same handle straight
     * afterwards. 4 KiB is far more than any magic number needs and small
     * enough that a 10 MB upload is not read twice.
     *
     * @param  resource  $stream
     */
    public static function forStream(mixed $stream): ?string
    {
        if (! is_resource($stream)) {
            return null;
        }

        $position = ftell($stream);
        rewind($stream);
        $head = fread($stream, 4096);
        rewind($stream);

        if ($position !== false && $position !== 0) {
            fseek($stream, $position);
        }

        if ($head === false || $head === '') {
            return null;
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return $type === false ? null : $type;
    }

    public static function matches(?string $mime, string $extension): bool
    {
        if ($mime === null) {
            return false;
        }

        $expected = self::EXPECTED[strtolower($extension)] ?? null;

        if ($expected === null) {
            return false;
        }

        return in_array(strtolower($mime), array_map('strtolower', $expected), true);
    }
}
