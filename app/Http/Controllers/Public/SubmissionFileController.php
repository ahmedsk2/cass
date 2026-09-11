<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SubmissionFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec section 6 and 8: `/files/{ulid}`, signed and expiring, streaming from
 * the private disk.
 *
 * There is no policy call here and no token check. The signature *is* the
 * capability: it was minted by the status page (which the author reached with
 * their token) or by the organizer panel (which checked SubmissionFilePolicy
 * before rendering the link). Adding a session check on top would break the
 * author case, and adding a token parameter would put the author's editing
 * credential into a URL that gets forwarded to a printer.
 *
 * What is still checked: the file exists, its submission has not been deleted,
 * and the object is really on disk.
 */
class SubmissionFileController extends Controller
{
    public function __invoke(string $ulid): StreamedResponse
    {
        $file = SubmissionFile::query()->where('ulid', $ulid)->first();

        abort_if($file === null, 404);

        // The `submission` relation applies Submission's SoftDeletes scope, so
        // a deleted abstract makes this null and the download stops - the
        // signed URL in an old email does not outlive the abstract.
        abort_if($file->submission === null, 404);

        abort_unless(Storage::disk('local')->exists((string) $file->path), 404);

        return Storage::disk('local')->download(
            (string) $file->path,
            (string) $file->original_name,
            [
                // The sniffed type from upload, never a guess from the
                // extension at download time.
                'Content-Type' => (string) $file->mime,
                // Belt and braces: SecurityHeaders sets this globally, and this
                // is the one response in the application whose body is
                // attacker-supplied bytes.
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}
