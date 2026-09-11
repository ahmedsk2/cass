<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Exceptions\SubmissionFileRejected;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Support\Files\SniffedMimeType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spec section 8: "Uploads are validated by size (10 MB per file), count,
 * extension and sniffed MIME, and are never executed or served directly."
 *
 * The order of the checks is deliberate: everything that can be decided without
 * reading the file comes first, so a 10 MB upload from a conference that
 * accepts no files at all is refused before a single byte is hashed.
 */
class StoreSubmissionFile
{
    public function handle(Submission $submission, UploadedFile $file): SubmissionFile
    {
        $conference = $submission->conference;

        // The conference soft deletes while the abstract survives - the case
        // Submission::isOpenToAuthor() documents - so this belongsTo resolves
        // to null on a public, unauthenticated upload. Reading max_files off
        // null is a warning Laravel promotes to an ErrorException, and the
        // count check below would then refuse with "this conference does not
        // accept file attachments", which is not what happened.
        if ($conference === null) {
            throw SubmissionFileRejected::conferenceUnavailable();
        }

        $maxFiles = (int) $conference->max_files;
        if ($submission->files()->count() >= $maxFiles) {
            throw SubmissionFileRejected::tooMany($maxFiles);
        }

        $allowed = $conference->allowedFileTypes();
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $allowed, true)) {
            throw SubmissionFileRejected::extension($extension, $allowed);
        }

        $limit = (int) config('cass.max_file_bytes');
        $size = (int) $file->getSize();

        if ($size > $limit) {
            throw SubmissionFileRejected::tooLarge($size, $limit);
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw SubmissionFileRejected::unreadable();
        }

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw SubmissionFileRejected::unreadable();
        }

        try {
            // Content, not the client's Content-Type header and not the name.
            // The forPath() fallback is the one SniffedMimeType::forStream()
            // documents: it refuses a handle it cannot rewind rather than
            // eating the head this method still has to hash and copy, and the
            // path is the same bytes read a second time.
            $mime = SniffedMimeType::forStream($stream) ?? SniffedMimeType::forPath($path);

            if (! SniffedMimeType::matches($mime, $extension)) {
                throw SubmissionFileRejected::contentMismatch($extension);
            }

            $sha = hash_file('sha256', $path);

            if ($sha === false) {
                throw SubmissionFileRejected::unreadable();
            }

            $duplicate = $submission->files()->where('sha256', $sha)->first();

            if ($duplicate !== null) {
                throw SubmissionFileRejected::duplicate((string) $duplicate->original_name);
            }

            $ulid = (string) Str::ulid();
            // {first 2 of sha256}/{ulid}.{ext}: the prefix keeps any one
            // directory to a few thousand entries, and the ULID in the name
            // means two submissions holding identical bytes still own separate
            // objects - so one of them deleting its copy cannot break the other.
            $storedPath = substr($sha, 0, 2).'/'.$ulid.'.'.$extension;

            // The `local` disk is configured throw=false, report=false
            // (config/filesystems.php), so a write onto a full or read-only
            // volume comes back as a bare `false` with nothing logged. Saving
            // the row regardless would tell the author their file is attached
            // and hand the organizer a 404 download, so this is where it stops.
            if (Storage::disk('local')->writeStream($storedPath, $stream) === false) {
                Log::error('Submission file could not be written to the private disk.', [
                    'submission_id' => $submission->getKey(),
                    'path' => $storedPath,
                    'bytes' => $size,
                ]);

                throw SubmissionFileRejected::storageFailed();
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $record = new SubmissionFile;
        $record->forceFill([
            'submission_id' => $submission->getKey(),
            'ulid' => $ulid,
            'original_name' => $this->safeName($file->getClientOriginalName(), $extension),
            'path' => $storedPath,
            'mime' => (string) $mime,
            'size' => $size,
            'sha256' => $sha,
            // Append: ((int) max) + 1 rather than count() + 1, so removing the
            // middle file of three does not give the next upload a taken sort.
            'sort' => ((int) $submission->files()->max('sort')) + 1,
        ]);

        // The object is on disk and the row is not, so every way out of this
        // save that is not success has to take the object with it. Content
        // addressing means the object belongs to exactly one row; with no row,
        // nothing will ever reference it and nothing will ever clean it up.
        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            // The duplicate check above is a read, and the unique
            // (submission_id, sha256) index is the thing that actually decides.
            // Two uploads of the same bytes in the same second both pass the
            // read; the loser must meet the same sentence as the author who
            // attached the file twice slowly, not a raw QueryException 500 on a
            // public page.
            Storage::disk('local')->delete($storedPath);

            $duplicate = $submission->files()->where('sha256', $sha)->first();

            throw SubmissionFileRejected::duplicate((string) ($duplicate->original_name ?? $record->original_name));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);

            throw $exception;
        }

        return $record;
    }

    /**
     * The original name is shown to organizers and put into a
     * Content-Disposition header. Keep it recognisable, drop anything that
     * could be read as a path, and keep the extension the content was checked
     * against - not whatever the name happened to end in.
     */
    private function safeName(string $name, string $extension): string
    {
        $base = pathinfo(str_replace(['\\', '/'], '-', $name), PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $base) ?? '');
        $base = Str::limit($base === '' ? 'attachment' : $base, 120, '');

        return $base.'.'.$extension;
    }
}
