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
     */
    public function temporaryUrl(?int $minutes = null): string
    {
        return URL::temporarySignedRoute(
            'files.download',
            now()->addMinutes($minutes ?? (int) config('cass.file_url_minutes')),
            ['ulid' => $this->ulid],
        );
    }

    public function readStream(): mixed
    {
        return Storage::disk('local')->readStream((string) $this->path);
    }
}
