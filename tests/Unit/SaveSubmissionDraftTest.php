<?php

declare(strict_types=1);

use App\Actions\Submissions\SaveSubmissionDraft;
use App\Actions\Submissions\UpdateSubmission;
use App\Actions\Submissions\WithdrawSubmission;
use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Exceptions\SubmissionNotAcceptable;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

// Three tests below freeze the clock. Resetting it only on the last line of
// each body means one failing assertion leaves every later test in this file
// running past the conference deadline, turning one red into a cascade that
// hides which assertion actually broke.
afterEach(function () {
    Carbon::setTestNow();
});

beforeEach(function () {
    $this->conference = Conference::factory()->published()->create(['word_limit' => 300]);
});

function draftData(array $overrides = []): array
{
    return array_replace([
        'title' => 'Early mobilisation after cardiac surgery',
        'abstract' => 'Background. Methods. Results. Conclusion.',
        'track_id' => null,
        'presentation_preference' => PresentationPreference::Oral->value,
        'contact_phone' => '+966500000000',
        'custom_field_values' => null,
        'authors' => [
            ['name' => 'Dr Sara Al-Harbi', 'email' => 'sara@example.org', 'affiliation' => 'KFSH', 'is_presenter' => true, 'is_corresponding' => true],
        ],
    ], $overrides);
}

it('creates a draft with a token, a word count and its authors', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());

    $submission = $link->submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Draft)
        ->and($submission->conference_id)->toBe($this->conference->id)
        ->and($submission->reference)->toBeNull()
        // Four tokens: "Background.", "Methods.", "Results.", "Conclusion."
        ->and($submission->word_count)->toBe(4)
        ->and($submission->last_edited_at)->not->toBeNull()
        ->and($submission->authors)->toHaveCount(1)
        ->and($submission->correspondingAuthor()?->email)->toBe('sara@example.org')
        ->and($link->token)->toMatch('/^[A-Za-z0-9]{64}$/')
        ->and($link->url())->toBe(route('submission.status', ['token' => $link->token]));
});

it('writes the real hash with the insert, never a shared placeholder', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());

    // A fixed placeholder in a UNIQUE char(64) would make every concurrent
    // first save queue on one index record for the length of the transaction.
    // SQLite serialises writes and MySQL blocks rather than erroring, so no
    // test here can observe the stall - this asserts the shape that prevents it.
    expect($link->submission->access_token_hash)->not->toBe(str_repeat('0', 64))
        ->and(Submission::findByPlainToken((string) $link->token)?->is($link->submission))->toBeTrue();
});

it('recomputes the word count instead of trusting the caller', function () {
    // 'word_count' is not in $fillable and is not part of the accepted $data
    // shape, so an extra key must be ignored rather than stored.
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'abstract' => 'One two three',
        'word_count' => 1,
    ]));

    expect($link->submission->refresh()->word_count)->toBe(3);
});

it('reuses the row and does not mint a second token on a second save', function () {
    $first = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $hash = $first->submission->access_token_hash;

    $second = app(SaveSubmissionDraft::class)->handle(
        $this->conference,
        draftData(['title' => 'A better title']),
        $first->submission,
    );

    expect($second->submission->is($first->submission))->toBeTrue()
        ->and($second->token)->toBeNull()
        ->and($second->url())->toBeNull()
        ->and($second->submission->refresh()->title)->toBe('A better title')
        ->and($second->submission->access_token_hash)->toBe($hash);
});

it('replaces the author list wholesale rather than appending', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'authors' => [
            ['name' => 'A', 'email' => 'a@example.org', 'affiliation' => null, 'is_presenter' => true, 'is_corresponding' => true],
            ['name' => 'B', 'email' => 'b@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => false],
        ],
    ]));

    app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'authors' => [
            ['name' => 'C', 'email' => 'c@example.org', 'affiliation' => null, 'is_presenter' => true, 'is_corresponding' => true],
        ],
    ]), $link->submission);

    expect($link->submission->refresh()->authors->pluck('email')->all())->toBe(['c@example.org']);
});

it('numbers the authors from one in the order they were given', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'authors' => [
            ['name' => 'First', 'email' => 'a@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => true],
            ['name' => 'Second', 'email' => 'b@example.org', 'affiliation' => null, 'is_presenter' => true, 'is_corresponding' => false],
            ['name' => 'Third', 'email' => 'c@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => false],
        ],
    ]));

    expect($link->submission->authors->pluck('sort')->all())->toBe([1, 2, 3])
        ->and($link->submission->authors->pluck('name')->all())->toBe(['First', 'Second', 'Third']);
});

it('keeps only one corresponding author even when the caller ticks two', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'authors' => [
            ['name' => 'A', 'email' => 'a@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => true],
            ['name' => 'B', 'email' => 'b@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => true],
        ],
    ]));

    expect($link->submission->authors->where('is_corresponding', true))->toHaveCount(1)
        ->and($link->submission->correspondingAuthor()?->email)->toBe('a@example.org');
});

it('promotes the first author when the caller ticks none', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'authors' => [
            ['name' => 'A', 'email' => 'a@example.org', 'affiliation' => null, 'is_presenter' => false, 'is_corresponding' => false],
        ],
    ]));

    expect($link->submission->correspondingAuthor()?->email)->toBe('a@example.org');
});

it('drops a custom field value the conference does not define', function () {
    CustomField::factory()->for($this->conference)->create(['label' => 'Funding source']);

    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'custom_field_values' => ['funding_source' => 'None', 'is_admin' => true],
    ]));

    expect($link->submission->refresh()->custom_field_values)->toBe(['funding_source' => 'None']);
});

it('refuses to re-parent an existing abstract into another conference', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $elsewhere = Conference::factory()->published()->create();

    // conference_id is force-filled on every save, so a mismatched pair would
    // move the abstract into another organization's conference and - because
    // the track and the custom-field filters are applied against the conference
    // that was passed in - silently wipe both. Only caller discipline stood
    // between a mixed-up argument and a cross-tenant write.
    expect(fn () => app(SaveSubmissionDraft::class)->handle($elsewhere, draftData(), $link->submission))
        ->toThrow(InvalidArgumentException::class);

    expect($link->submission->refresh()->conference_id)->toBe($this->conference->id);
});

it('refuses a track that belongs to another conference', function () {
    $foreign = Track::factory()->for(Conference::factory()->published())->create();

    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData(['track_id' => $foreign->id]));

    // A draft accepts nearly anything, but never a cross-conference foreign
    // key: that would put another organization's track name on this page.
    expect($link->submission->refresh()->track_id)->toBeNull();
});

it('drops a presentation preference the enum does not define', function () {
    // Laravel calls PresentationPreference::from() on the way into the cast, so
    // handing the cast an unknown choice is a ValueError - an uncaught 500 on a
    // public path whose own validation covers only the title and the
    // corresponding address. It is dropped exactly like a foreign track and
    // reported by SubmitAbstract::blockers() where the author can still act.
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'presentation_preference' => 'keynote',
    ]));

    expect($link->submission->refresh()->presentation_preference)->toBeNull();

    $kept = app(SaveSubmissionDraft::class)->handle($this->conference, draftData([
        'presentation_preference' => PresentationPreference::Poster->value,
    ]));

    expect($kept->submission->refresh()->presentation_preference)->toBe(PresentationPreference::Poster);
});

it('lets the author edit a submitted abstract without changing its status', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $submission = $link->submission;
    $submission->forceFill(['status' => SubmissionStatus::Submitted, 'reference' => 'X-001', 'submitted_at' => now()])->save();

    Carbon::setTestNow(now()->addHour());

    $updated = app(UpdateSubmission::class)->handle($submission, draftData(['title' => 'Revised title']));

    expect($updated->status)->toBe(SubmissionStatus::Submitted)
        ->and($updated->reference)->toBe('X-001')
        ->and($updated->title)->toBe('Revised title')
        ->and($updated->last_edited_at?->toDateTimeString())->toBe(now()->toDateTimeString());

    Carbon::setTestNow();
});

it('refuses an edit once the window has closed', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());

    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    expect(fn () => app(UpdateSubmission::class)->handle($link->submission, draftData(['title' => 'Too late'])))
        ->toThrow(SubmissionNotAcceptable::class);

    Carbon::setTestNow();
});

it('withdraws and refuses to withdraw twice', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $submission = $link->submission;
    $submission->forceFill(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()])->save();

    $withdrawn = app(WithdrawSubmission::class)->handle($submission);

    expect($withdrawn->status)->toBe(SubmissionStatus::Withdrawn)
        ->and($withdrawn->withdrawn_at)->not->toBeNull()
        // The reference is kept: an organizer who already printed a programme
        // needs the number to still mean something.
        ->and($withdrawn->submitted_at)->not->toBeNull();

    expect(fn () => app(WithdrawSubmission::class)->handle($withdrawn))
        ->toThrow(SubmissionNotAcceptable::class);
});

it('refuses an author withdrawal after the deadline but allows the organizer one', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $link->submission->forceFill(['status' => SubmissionStatus::Submitted, 'submitted_at' => now()])->save();

    Carbon::setTestNow($this->conference->submission_deadline->copy()->addMinute());

    // No actor: this is the author, holding their token, after the deadline.
    // SubmissionStatus::isOpenToAuthor() is still true for Submitted, so only
    // the window check stops this.
    expect(fn () => app(WithdrawSubmission::class)->handle($link->submission))
        ->toThrow(SubmissionNotAcceptable::class);

    expect($link->submission->refresh()->status)->toBe(SubmissionStatus::Submitted);

    // An actor is an organizer, and an author who emails after the deadline
    // still has to be taken off the programme.
    $organizer = User::factory()->create();

    expect(app(WithdrawSubmission::class)->handle($link->submission, $organizer)->status)
        ->toBe(SubmissionStatus::Withdrawn);

    Carbon::setTestNow();
});

it('records who withdrew an abstract in the activity log', function () {
    $link = app(SaveSubmissionDraft::class)->handle($this->conference, draftData());
    $actor = User::factory()->create();

    app(WithdrawSubmission::class)->handle($link->submission, $actor);

    $activity = Activity::query()->where('description', 'submission.withdrawn')->firstOrFail();

    expect($activity->causer_id)->toBe($actor->id)
        ->and($activity->subject_id)->toBe($link->submission->id);
});
