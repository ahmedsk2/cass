<?php

declare(strict_types=1);

use App\Actions\Conferences\ArchiveConference;
use App\Actions\Conferences\CloseSubmissions;
use App\Actions\Conferences\CreateConference;
use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Actions\Conferences\PublishConference;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewQuestionType;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function publishableConference(): Conference
{
    $conference = Conference::factory()
        ->for(Organization::factory()->approved())
        ->withSubmissionWindow()
        ->create();

    app(CreateDefaultReviewForm::class)->handle($conference);

    return $conference->fresh() ?? $conference;
}

it('creates the nine legacy questions as a likert 1 to 5 template', function () {
    $conference = Conference::factory()->create();

    $form = app(CreateDefaultReviewForm::class)->handle($conference);

    expect($form->questions()->count())->toBe(9)
        ->and($form->is_active)->toBeTrue()
        ->and($form->isLocked())->toBeFalse();

    $questions = $form->questions()->get();

    expect($questions->pluck('prompt')->first())
        ->toBe('Originality and Innovation: How original and innovative is the research presented in the abstract?')
        ->and($questions->pluck('prompt')->last())
        ->toBe('Do you recommend this abstract for oral presentation? (1 = do not recommend, 5 = strongly recommend)')
        ->and($questions->pluck('sort')->all())->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9]);

    $questions->each(function ($question): void {
        expect($question->type)->toBe(ReviewQuestionType::Likert)
            ->and($question->scale_min)->toBe(1)
            ->and($question->scale_max)->toBe(5)
            ->and($question->weight)->toBe('1.00')
            ->and($question->required)->toBeTrue();
    });
});

it('is idempotent so a second call does not duplicate the template', function () {
    $conference = Conference::factory()->create();

    $first = app(CreateDefaultReviewForm::class)->handle($conference);
    $second = app(CreateDefaultReviewForm::class)->handle($conference);

    expect($second->is($first))->toBeTrue()
        ->and($first->questions()->count())->toBe(9);
});

it('creates a conference under the tenant with a derived slug and a review form', function () {
    $organization = Organization::factory()->approved()->create();

    $conference = app(CreateConference::class)->handle($organization, [
        'name' => 'Winter Pediatric Symposium',
        'timezone' => 'Asia/Riyadh',
        'word_limit' => 350,
        'max_files' => 2,
        'allowed_file_types' => ['pdf'],
        'presentation_types' => ['oral', 'poster'],
    ]);

    expect($conference->organization->is($organization))->toBeTrue()
        ->and($conference->slug)->toBe('winter-pediatric-symposium')
        ->and($conference->status)->toBe(ConferenceStatus::Draft)
        ->and($conference->word_limit)->toBe(350)
        ->and($conference->reviewForm?->questions()->count())->toBe(9);
});

it('reports no blockers for a conference that satisfies the gate', function () {
    expect(app(PublishConference::class)->blockers(publishableConference()))->toBe([]);
});

it('blocks publishing until the organization is approved', function () {
    $conference = Conference::factory()->for(Organization::factory())->withSubmissionWindow()->create();
    app(CreateDefaultReviewForm::class)->handle($conference);

    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('Your organization is still waiting for platform approval. You can publish as soon as it is approved.');
});

it('blocks publishing for a suspended organization', function () {
    $conference = Conference::factory()->for(Organization::factory()->suspended())->withSubmissionWindow()->create();
    app(CreateDefaultReviewForm::class)->handle($conference);

    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('This organization is suspended, so its conferences cannot be published.');
});

it('blocks publishing without a submission window', function () {
    $conference = Conference::factory()->for(Organization::factory()->approved())->create();
    app(CreateDefaultReviewForm::class)->handle($conference);

    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('Set both a submission opening date and a submission deadline.');
});

it('blocks publishing when the deadline has passed or precedes the opening date', function () {
    $conference = publishableConference();

    $conference->forceFill(['submission_deadline' => now()->subDay()])->save();
    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('The submission deadline is in the past. Choose a future date and time.');

    $conference->forceFill([
        'submission_opens_at' => now()->addMonths(2),
        'submission_deadline' => now()->addMonth(),
    ])->save();
    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('The submission deadline must come after the submission opening date.');
});

it('blocks publishing without an active review form that has questions', function () {
    $conference = Conference::factory()->for(Organization::factory()->approved())->withSubmissionWindow()->create();

    expect(app(PublishConference::class)->blockers($conference))
        ->toContain('The review form has no questions yet. Add at least one before publishing.');

    $form = app(CreateDefaultReviewForm::class)->handle($conference);
    $form->questions()->delete();

    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('The review form has no questions yet. Add at least one before publishing.');
});

it('blocks publishing an archived conference', function () {
    $conference = publishableConference();
    $conference->forceFill(['status' => ConferenceStatus::Archived])->save();

    expect(app(PublishConference::class)->blockers($conference->fresh() ?? $conference))
        ->toContain('An archived conference cannot be published again.');
});

it('publishes, stamps the time, creates the short link and logs the change', function () {
    $conference = publishableConference();
    $actor = User::factory()->create();
    $conference->organization->addMember($actor, OrganizationRole::Owner);

    $published = app(PublishConference::class)->handle($conference, $actor);

    expect($published->status)->toBe(ConferenceStatus::Open)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->shortLink)->not->toBeNull()
        ->and(strlen((string) $published->shortLink?->code))->toBe(8)
        ->and(Activity::query()->where('description', 'conference.published')->count())->toBe(1);
});

it('reuses the printed short code when a conference is republished', function () {
    $conference = publishableConference();
    $actor = User::factory()->create();

    $code = app(PublishConference::class)->handle($conference, $actor)->shortLink?->code;
    app(CloseSubmissions::class)->handle($conference->fresh() ?? $conference, $actor);
    $republished = app(PublishConference::class)->handle($conference->fresh() ?? $conference, $actor);

    expect($republished->shortLink?->code)->toBe($code)
        ->and(ShortLink::count())->toBe(1);
});

it('refuses to publish when the gate is not satisfied', function () {
    $conference = Conference::factory()->for(Organization::factory())->create();
    $actor = User::factory()->create();

    expect(fn () => app(PublishConference::class)->handle($conference, $actor))
        ->toThrow(ConferenceNotPublishable::class);

    expect($conference->fresh()?->status)->toBe(ConferenceStatus::Draft);
});

it('closes submissions on an open conference and refuses otherwise', function () {
    $conference = publishableConference();
    $actor = User::factory()->create();
    app(PublishConference::class)->handle($conference, $actor);

    $closed = app(CloseSubmissions::class)->handle($conference->fresh() ?? $conference, $actor);
    expect($closed->status)->toBe(ConferenceStatus::Closed)
        ->and(Activity::query()->where('description', 'conference.closed')->count())->toBe(1);

    expect(fn () => app(CloseSubmissions::class)->handle($closed, $actor))
        ->toThrow(InvalidArgumentException::class);
});

it('archives any live conference and refuses to archive twice', function () {
    $conference = publishableConference();
    $actor = User::factory()->create();

    $archived = app(ArchiveConference::class)->handle($conference, $actor);
    expect($archived->status)->toBe(ConferenceStatus::Archived)
        ->and(Activity::query()->where('description', 'conference.archived')->count())->toBe(1);

    expect(fn () => app(ArchiveConference::class)->handle($archived, $actor))
        ->toThrow(InvalidArgumentException::class);
});
