<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tokens\SubmissionToken;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/**
 * Spec section 10: "ranking table for 500 submissions in under 1 s."
 *
 * Two tests, doing two different jobs. The query-count one is the gate - it is
 * deterministic and it fails the moment somebody adds a per-row aggregate. The
 * wall-clock one is the budget itself and is skipped on CI, because a shared
 * runner's clock measures the runner.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'status' => ConferenceStatus::Reviewing,
        'reviewers_per_submission' => 2,
        'review_deadline' => now()->addMonth(),
    ]);

    // A raw insert, not 500 factory calls: the factory would take longer than
    // the thing being measured and would make the test a measurement of
    // Faker. Every column the ranking reads is set here.
    $now = now()->toDateTimeString();
    $rows = [];

    for ($i = 1; $i <= 500; $i++) {
        $rows[] = [
            'ulid' => (string) Str::ulid(),
            'conference_id' => $this->conference->id,
            'track_id' => null,
            'reference' => sprintf('PERF26-%03d', $i),
            'status' => SubmissionStatus::UnderReview->value,
            'title' => "Abstract number {$i}",
            'abstract' => 'A body of text that the ranking never reads.',
            'word_count' => 9,
            'presentation_preference' => 'oral',
            'contact_phone' => null,
            'custom_field_values' => null,
            'access_token_hash' => SubmissionToken::hash(SubmissionToken::generate()),
            'score' => 100 - ($i % 100),
            'score_spread' => $i % 17,
            'review_count' => 2,
            'scored_at' => $now,
            'decision' => null,
            'decision_notified_at' => null,
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('submissions')->insert($chunk);
    }
});

it('renders five hundred abstracts without touching the reviews table', function () {
    /** @var list<string> $queries */
    $queries = [];

    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->set('tableRecordsPerPage', 'all')
        ->assertCountTableRecords(500);

    // THE assertion. Every number on this table is a column on `submissions`;
    // the moment one of them becomes `withAvg('reviews', 'score')` or a
    // per-row `$record->reviews()->count()`, this goes red - which is the whole
    // reason the columns exist.
    $touchesReviews = array_values(array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, '"reviews"')
            || str_contains($sql, '`reviews`')
            || str_contains($sql, '"review_answers"')
            || str_contains($sql, '`review_answers`'),
    ));

    expect($touchesReviews)->toBe([]);

    // A ceiling rather than an exact number: Filament's own boot issues a
    // handful (the user, the tenant, the session) and a Filament upgrade may
    // move that by one or two without anything being wrong. Thirty is far
    // below the 501 a per-row aggregate would produce, which is the failure
    // this bounds.
    //
    // The measured number on this tree is 20, and every one of them is
    // bounded: the case drives TWO renders, the mount and the `set()` above,
    // and each costs the conference, the tenant, the membership checks, the
    // summary strip's two aggregates, the paginator's count, the page itself
    // and the track filter's options. Not one is per row - which is what the
    // assertion above, not this one, actually proves.
    expect(count($queries))->toBeLessThan(30);
});

it('renders five hundred notified abstracts without one conference query per row', function () {
    // The state the first case cannot reach: every letter has gone, so
    // resendDecision() is on all 500 rows and its visible() runs 500 times.
    // The ranking query selects `submissions` alone, so anything that closure
    // reads off a relation is a lazy load per RENDERED row - which is exactly
    // the regression the ceiling below exists to catch, and the reason both
    // authorization answers and the conference are arguments rather than
    // per-row lookups.
    DB::table('submissions')
        ->where('conference_id', $this->conference->id)
        ->update([
            'status' => SubmissionStatus::Accepted->value,
            'decision' => Decision::AcceptedOral->value,
            'decision_notified_at' => now()->toDateTimeString(),
        ]);

    /** @var list<string> $queries */
    $queries = [];

    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->set('tableRecordsPerPage', 'all')
        ->assertCountTableRecords(500);

    // The same ceiling as the undecided render, because sending the letters
    // must not change the shape of the page's query plan.
    expect(count($queries))->toBeLessThan(30);
});

it('renders five hundred abstracts inside the one-second budget of spec section 10', function () {
    $started = microtime(true);

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->set('tableRecordsPerPage', 'all')
        ->assertCountTableRecords(500);

    $elapsed = microtime(true) - $started;

    expect($elapsed)->toBeLessThan(1.0);
})->skip(
    fn (): bool => (bool) env('CI'),
    'Wall clock on a shared GitHub runner measures the runner, not the query: the same tree '
    .'has taken 0.4s and 2.1s an hour apart. The deterministic gate is the query-count test '
    .'above, which fails for the actual regression this budget exists to catch. Run this one '
    .'locally, and on the production host before launch (docs/runbooks/deploy-production.md).',
);
