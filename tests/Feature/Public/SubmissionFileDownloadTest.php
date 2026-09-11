<?php

declare(strict_types=1);

use App\Actions\Submissions\DeleteSubmissionFile;
use App\Actions\Submissions\StoreSubmissionFile;
use App\Exceptions\SubmissionFileRejected;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

use function Pest\Laravel\get;

beforeEach(function () {
    Storage::fake('local');

    $this->conference = Conference::factory()->published()->create([
        'max_files' => 2,
        'allowed_file_types' => ['pdf'],
    ]);
    $this->submission = Submission::factory()->for($this->conference)->create();
});

function uploadedPdf(string $name = 'abstract.pdf'): UploadedFile
{
    // A copy of the real fixture, so finfo sees a real %PDF- header.
    // UploadedFile::fake()->create() writes zero bytes, which sniffs as
    // application/x-empty and would make every MIME assertion here vacuous.
    return new UploadedFile(base_path('tests/Fixtures/abstract.pdf'), $name, 'application/pdf', null, true);
}

it('stores a pdf content-addressed on the private disk', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());

    $sha = hash_file('sha256', base_path('tests/Fixtures/abstract.pdf'));

    expect($file->original_name)->toBe('abstract.pdf')
        ->and($file->mime)->toBe('application/pdf')
        ->and($file->sha256)->toBe($sha)
        ->and($file->size)->toBe(filesize(base_path('tests/Fixtures/abstract.pdf')))
        ->and($file->sort)->toBe(1)
        ->and($file->path)->toBe(substr($sha, 0, 2).'/'.$file->ulid.'.pdf');

    // The bytes, not merely the presence of an object: assertExists() alone is
    // perfectly happy with the 0-byte file a truncated write leaves behind, so
    // the second argument is what makes a stream-position regression in
    // SniffedMimeType::forStream() fail here instead of in production.
    Storage::disk('local')->assertExists($file->path, fixturePdfBytes());
    // The private disk's root is storage/app/private and nothing serves it;
    // the public disk must never see a submission file.
    Storage::disk('public')->assertMissing($file->path);
});

it('appends rather than colliding on sort', function () {
    $first = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf('one.pdf'));
    // A second, genuinely different file: the same bytes would be a duplicate.
    $second = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdfWithSuffix('two.pdf', 'second'));

    expect([$first->sort, $second->sort])->toBe([1, 2]);
});

it('sees through a png wearing a pdf name', function () {
    $png = new UploadedFile(base_path('tests/Fixtures/not-really.pdf'), 'abstract.pdf', 'application/pdf', null, true);

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, $png))
        ->toThrow(SubmissionFileRejected::class, 'does not look like a PDF');

    expect($this->submission->files()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses an extension the conference does not allow', function () {
    $doc = new UploadedFile(base_path('tests/Fixtures/abstract.pdf'), 'abstract.docx', 'application/pdf', null, true);

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, $doc))
        ->toThrow(SubmissionFileRejected::class, 'PDF');
});

it('refuses a file over the size cap', function () {
    config()->set('cass.max_file_bytes', 100);

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf()))
        ->toThrow(SubmissionFileRejected::class, 'larger than');
});

it('refuses more files than the conference allows', function () {
    app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf('one.pdf'));
    app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdfWithSuffix('two.pdf', 'second'));

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdfWithSuffix('three.pdf', 'third')))
        ->toThrow(SubmissionFileRejected::class, '2 file');
});

it('refuses the same bytes twice in one submission but allows them in another', function () {
    app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf('renamed.pdf')))
        ->toThrow(SubmissionFileRejected::class, 'already attached');

    $other = Submission::factory()->for($this->conference)->create();
    $second = app(StoreSubmissionFile::class)->handle($other, uploadedPdf());

    expect($second->sha256)->toBe($this->submission->files()->first()?->sha256)
        // Same content, different object: the ULID in the file name means one
        // submission deleting its copy can never remove another's.
        ->and($second->path)->not->toBe($this->submission->files()->first()?->path);
});

it('refuses a file the private disk did not actually write', function () {
    // The `local` disk is configured throw=false, report=false
    // (config/filesystems.php), so a write that fails on a full or read-only
    // volume comes back as a bare `false`. Saving the row anyway would tell the
    // author their file is attached and hand the organizer a 404 download.
    $disk = Mockery::mock(Storage::disk('local'));
    $disk->shouldReceive('writeStream')->once()->andReturnFalse();
    Storage::set('local', $disk);

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf()))
        ->toThrow(SubmissionFileRejected::class, 'could not be saved');

    expect(SubmissionFile::query()->count())->toBe(0);
});

it('deletes the row and the object', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());
    $path = (string) $file->path;

    app(DeleteSubmissionFile::class)->handle($file);

    Storage::disk('local')->assertMissing($path);
    expect(SubmissionFile::query()->count())->toBe(0);
});

it('keeps the object when the row delete fails', function () {
    // The failure the docblock says this action avoids: a live row whose object
    // is gone, which is a download link the organizer cannot explain. Deleting
    // the object first put it back - the transaction rolls the row back and the
    // bytes are already off the disk. The row goes first; an orphan object is
    // the acceptable half, and the Plan 6 purge sweeps it.
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());
    $path = (string) $file->path;

    SubmissionFile::deleting(function (): void {
        throw new RuntimeException('the row delete failed');
    });

    expect(fn () => app(DeleteSubmissionFile::class)->handle($file))->toThrow(RuntimeException::class);

    Storage::disk('local')->assertExists($path);
    expect(SubmissionFile::query()->count())->toBe(1);
});

it('refuses an attachment when the conference is gone rather than reading a property on null', function () {
    // Submission::isOpenToAuthor() documents the case: the conference soft
    // deletes while the abstract survives, so `$submission->conference` is
    // null. Reading max_files off that is a warning Laravel promotes to an
    // ErrorException - a 500 on a public upload - and the count check would
    // then refuse with "this conference does not accept file attachments",
    // which is not what happened.
    $this->conference->delete();

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission->fresh(), uploadedPdf()))
        ->toThrow(SubmissionFileRejected::class, 'no longer accepting');

    expect(SubmissionFile::query()->count())->toBe(0);
});

it('answers a duplicate that lands between the check and the insert with the friendly message', function () {
    // The unique (submission_id, sha256) index behind the check-then-insert:
    // two uploads of the same bytes in the same second both pass the check and
    // the second insert raises a QueryException, which is a raw 500 on a public
    // page rather than the sentence the author can act on. The listener below
    // is the concurrent request, inserting in the gap.
    $first = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf('one.pdf'));

    SubmissionFile::creating(function (SubmissionFile $file) use ($first): void {
        SubmissionFile::query()->insert([
            'submission_id' => $this->submission->getKey(),
            'ulid' => (string) Str::ulid(),
            'original_name' => 'race.pdf',
            'path' => 'zz/race.pdf',
            'mime' => 'application/pdf',
            'size' => 1,
            'sha256' => (string) $first->sha256,
            'sort' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    // The row the action checked against is gone, so its own duplicate check
    // passes and the insert is the thing that collides.
    $first->forceDelete();

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission->fresh(), uploadedPdf('two.pdf')))
        ->toThrow(SubmissionFileRejected::class, 'already attached');
});

it('does not leave the object behind when the row cannot be written', function () {
    SubmissionFile::creating(function (): void {
        throw new RuntimeException('the insert failed');
    });

    expect(fn () => app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf()))
        ->toThrow(RuntimeException::class);

    // Content addressing means the object belongs to exactly one row. With no
    // row, nothing will ever reference it or clean it up.
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('serves a signed url as an attachment and nothing else', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());

    $response = get($file->temporaryUrl());

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($response->headers->get('content-disposition'))->toStartWith('attachment;')
        ->toContain('abstract.pdf')
        ->and($response->headers->get('cache-control'))->toContain('no-store')
        // The whole round trip, byte for byte: a 200 with the right headers over
        // a truncated body is the failure this route exists to make impossible.
        ->and($response->streamedContent())->toBe(fixturePdfBytes());
});

it('refuses an unsigned, an expired and a tampered url', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());
    $other = Submission::factory()->for($this->conference)->create();
    $otherFile = app(StoreSubmissionFile::class)->handle($other, uploadedPdfWithSuffix('other.pdf', 'other'));

    // No signature at all.
    get('/files/'.$file->ulid)->assertForbidden();

    // A signature that has run out.
    $expired = URL::temporarySignedRoute('files.download', now()->subMinute(), ['ulid' => $file->ulid]);
    get($expired)->assertForbidden();

    // The signature of one file, pointed at another: the ULID is inside the
    // signed payload, so swapping it invalidates the whole URL.
    $tampered = str_replace((string) $file->ulid, (string) $otherFile->ulid, $file->temporaryUrl());
    get($tampered)->assertForbidden();
});

it('404s a valid signature over a file or submission that is gone', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());
    $url = $file->temporaryUrl();

    get($url)->assertOk();

    $this->submission->delete();

    // The signature is still valid; there is simply nothing to serve. A
    // soft-deleted submission is gone to everyone outside the admin panel.
    get($url)->assertNotFound();
});

it('404s a well-formed ulid that was never a file', function () {
    $url = URL::temporarySignedRoute('files.download', now()->addMinutes(30), ['ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

    get($url)->assertNotFound();
});

it('refuses a ulid that is not a ulid before reaching the controller', function () {
    // The route constraint is Crockford base32: no I, L, O or U. A path that
    // cannot be a ULID must not reach the database at all.
    get('/files/not-a-ulid')->assertNotFound();
    get('/files/01ARZ3NDEKTSV4RRFFQ69G5FAU')->assertNotFound();
});

/** The exact bytes every uploadedPdf() carries, for comparing what was stored and served. */
function fixturePdfBytes(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/abstract.pdf'));
}

/** A distinct PDF, so the sha256 differs from the fixture's. */
function uploadedPdfWithSuffix(string $name, string $suffix): UploadedFile
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$suffix.'-'.$name;
    file_put_contents($path, file_get_contents(base_path('tests/Fixtures/abstract.pdf')).'%% '.$suffix."\n");

    return new UploadedFile($path, $name, 'application/pdf', null, true);
}
