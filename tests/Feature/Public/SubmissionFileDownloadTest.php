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

    Storage::disk('local')->assertExists($file->path);
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

it('deletes the row and the object', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());
    $path = (string) $file->path;

    app(DeleteSubmissionFile::class)->handle($file);

    Storage::disk('local')->assertMissing($path);
    expect(SubmissionFile::query()->count())->toBe(0);
});

it('serves a signed url as an attachment and nothing else', function () {
    $file = app(StoreSubmissionFile::class)->handle($this->submission, uploadedPdf());

    $response = get($file->temporaryUrl());

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff');

    expect($response->headers->get('content-disposition'))->toStartWith('attachment;')
        ->toContain('abstract.pdf')
        ->and($response->headers->get('cache-control'))->toContain('no-store');
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

/** A distinct PDF, so the sha256 differs from the fixture's. */
function uploadedPdfWithSuffix(string $name, string $suffix): UploadedFile
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$suffix.'-'.$name;
    file_put_contents($path, file_get_contents(base_path('tests/Fixtures/abstract.pdf')).'%% '.$suffix."\n");

    return new UploadedFile($path, $name, 'application/pdf', null, true);
}
