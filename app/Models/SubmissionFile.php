<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SubmissionFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class SubmissionFile extends Model
{
    /** @use HasFactory<SubmissionFileFactory> */
    use HasFactory;

    /**
     * Every column is written by StoreSubmissionFile from the sniffed stream,
     * never from the request: a `path`, a `mime` or a `sha256` that a caller
     * could mass assign is a path-traversal and a content-type spoof in one.
     */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer', 'sort' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * Spec section 8: downloads only through signed routes. Thirty minutes is
     * long enough to click a link in an email client that prefetches, short
     * enough that a forwarded URL is dead by the time it is forwarded.
     *
     * `blind` travels *inside* the signature (fact 19), so a reviewer cannot
     * remove it to learn the author's file name. It is absent from an
     * organizer's link entirely, which is also what the test asserts.
     */
    public function temporaryUrl(?int $minutes = null, bool $blind = false): string
    {
        $parameters = ['ulid' => $this->ulid];

        if ($blind) {
            $parameters['blind'] = 1;
        }

        return URL::temporarySignedRoute(
            'files.download',
            now()->addMinutes($minutes ?? (int) config('cass.file_url_minutes')),
            $parameters,
        );
    }

    /**
     * The stored `original_name` is the only place an extension lives - there
     * is no `extension` column - so blindName() below would have nothing to
     * work from without this. With the PATHINFO_EXTENSION flag, pathinfo()
     * answers a plain string and gives back `''` for a name with no dot at all
     * (checked on the 8.4.23 here: `pathinfo('foo', PATHINFO_EXTENSION)` is
     * `string(0) ""`), which is exactly the case blindName() tests for.
     * `original_name` is cast because nothing on this model casts it, and the
     * answer is lower-cased so `Poster.PDF` and `poster.pdf` both blind to
     * `attachment-1.pdf`.
     */
    public function extension(): string
    {
        return mb_strtolower((string) pathinfo((string) $this->original_name, PATHINFO_EXTENSION));
    }

    /**
     * What a blind reviewer sees and downloads. Plan 3's backlog raised this:
     * an author who calls their PDF `al-harbi-kfsh-final.pdf` has
     * deanonymised themselves, and hiding the authors block while serving that
     * file name would be theatre.
     */
    public function blindName(): string
    {
        $extension = $this->extension();
        $position = max(1, (int) $this->sort);

        return 'attachment-'.$position.($extension === '' ? '' : '.'.$extension);
    }

    public function readStream(): mixed
    {
        return Storage::disk('local')->readStream((string) $this->path);
    }
}
