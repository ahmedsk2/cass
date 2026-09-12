<?php

declare(strict_types=1);

use App\Actions\Decisions\ApplyDecision;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\OrganizationRole;
use App\Enums\SubmissionStatus;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceRanking;
use App\Filament\Organizer\Resources\Submissions\Pages\ViewSubmission;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->closed()->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);

    $this->first = Submission::factory()->for($this->conference)->scored(91.0)->create(['title' => 'The good one']);
    $this->first->forceFill(['reference' => 'AAM26-001'])->save();

    $this->second = Submission::factory()->for($this->conference)->scored(40.0)->create(['title' => 'The other one']);
    $this->second->forceFill(['reference' => 'AAM26-002'])->save();
});

it('decides one row from the ranking', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('decide', $this->first, [
            'decision' => Decision::AcceptedOral->value,
            'note' => 'Best reviewed abstract in the track.',
        ])
        ->assertHasNoTableActionErrors();

    expect($this->first->fresh()?->decision)->toBe(Decision::AcceptedOral)
        ->and($this->first->fresh()?->status)->toBe(SubmissionStatus::Accepted)
        ->and($this->first->fresh()?->currentDecision()?->note)->toBe('Best reviewed abstract in the track.');
});

it('decides a whole selection at once, skips what it may not touch, and says so', function () {
    $notified = Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral, notified: true)->create();
    $notified->forceFill(['reference' => 'AAM26-003'])->save();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableBulkAction('decideSelected', [$this->first, $this->second, $notified], [
            'decision' => Decision::Rejected->value,
        ])
        ->assertNotified();

    expect($this->first->fresh()?->decision)->toBe(Decision::Rejected)
        ->and($this->second->fresh()?->decision)->toBe(Decision::Rejected)
        // Already emailed: refused by ApplyDecision, and the column is
        // untouched. The report is what tells the organizer which row - without
        // this assertion the refusal path and summarise() are both untested and
        // a bulk action that silently skipped rows would pass.
        ->and($notified->fresh()?->decision)->toBe(Decision::AcceptedOral)
        ->and($notified->fresh()?->decision_notified_at)->not->toBeNull();
});

it('bounds one bulk decide to the configured chunk', function () {
    // decideSelected() walks the WHOLE selection asking the Gate per row and
    // then running ApplyDecision's own read and transaction - about eleven
    // queries a row - and "select all" on the 500-abstract conference spec
    // section 10 budgets for is several thousand of them inside one php-fpm
    // request. Bounded rather than refused: Filament applies this as a LIMIT on
    // the selection query, so the organizer clicks again, exactly as
    // SendDecisionEmails' send_chunk makes them.
    config()->set('cass.decisions.decide_chunk', 2);

    $third = Submission::factory()->for($this->conference)->submitted()->create();
    $third->forceFill(['reference' => 'AAM26-004'])->save();

    $component = livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()]);

    expect($component->instance()->getTable()->getMaxSelectableRecords())->toBe(2);

    $component
        ->callTableBulkAction('decideSelected', [$this->first, $this->second, $third], [
            'decision' => Decision::Rejected->value,
        ])
        ->assertNotified();

    $decided = collect([$this->first, $this->second, $third])
        ->filter(fn (Submission $row): bool => $row->fresh()?->decision !== null)
        ->count();

    expect($decided)->toBe(2);
});

it('ignores a foreign record id handed to the bulk decision', function () {
    // Filament resolves a selection with $table->getQuery()->whereKey($ids)
    // (vendor/filament/tables/src/Concerns/HasBulkActions.php:305), so the
    // conference scope in ConferenceRanking::table()'s query closure is the
    // whole of the isolation on this path - the ONE path where the client
    // chooses which primary keys the server acts on. This case pins that scope,
    // not the Gate: a foreign row never reaches the per-row Gate loop, because
    // it is dropped before the loop runs. It goes red the day somebody moves
    // the conference scope out of the query closure and into a filter.
    $theirs = withoutTenant(fn (): Submission => Submission::factory()->submitted()->create());
    $sameOrgOtherConference = Conference::factory()->for($this->organization)->closed()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);
    $neighbour = Submission::factory()->for($sameOrgOtherConference)->submitted()->create();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableBulkAction('decideSelected', [$this->first, $theirs, $neighbour], [
            'decision' => Decision::Rejected->value,
        ]);

    expect($this->first->fresh()?->decision)->toBe(Decision::Rejected)
        ->and($theirs->fresh()?->decision)->toBeNull()
        ->and($theirs->fresh()?->decisions()->count())->toBe(0)
        // Another conference of the SAME organization passes the decide policy
        // (it is view(), i.e. membership) and is kept out by the table query
        // alone - which is exactly why the table query has to be the scope.
        ->and($neighbour->fresh()?->decision)->toBeNull()
        ->and($neighbour->fresh()?->decisions()->count())->toBe(0);
});

it('ignores a foreign record id handed to the row decision', function () {
    $theirs = withoutTenant(fn (): Submission => Submission::factory()->submitted()->create());

    // Filament 5.8.1 resolves a row action's record through the TABLE's own
    // query and refuses outright when it is not there
    // (InteractsWithActions::resolveAction(),
    // vendor/filament/actions/src/Concerns/InteractsWithActions.php:707-712),
    // rather than running the action with a record it could not find. The
    // refusal IS the isolation this case is about, so it is asserted rather
    // than swallowed - and the row below proves nothing was written on the way
    // to it.
    expect(fn () => livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('decide', $theirs, ['decision' => Decision::Rejected->value]))
        ->toThrow(ActionNotResolvableException::class);

    expect($theirs->fresh()?->decision)->toBeNull()
        ->and($theirs->fresh()?->decisions()->count())->toBe(0);
});

it('hides the plain decide action once the letter has gone, and offers the change instead', function () {
    $this->first->forceFill([
        'decision' => Decision::AcceptedOral,
        'status' => SubmissionStatus::Accepted,
        'decision_notified_at' => now(),
    ])->save();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->assertTableActionHidden('decide', $this->first)
        ->assertTableActionVisible('changeDecision', $this->first)
        // And the row that has not been notified still has the plain one.
        ->assertTableActionVisible('decide', $this->second)
        ->assertTableActionHidden('changeDecision', $this->second);
});

it('changes a notified decision and puts the row back in the send queue', function () {
    // The notified row as production makes one: a decision applied through the
    // panel - which is what leaves the history row the change below appends to
    // - and then the stamp the send sets. Writing the three columns with a bare
    // forceFill would leave no history at all, and "appends to the history,
    // and the superseded row survives" is the whole of what this case pins.
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('decide', $this->first, ['decision' => Decision::AcceptedOral->value]);

    $this->first->forceFill(['decision_notified_at' => now()])->save();

    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('changeDecision', $this->first, [
            'decision' => Decision::AcceptedPoster->value,
            'note' => 'Room change.',
        ])
        ->assertHasNoTableActionErrors();

    expect($this->first->fresh()?->decision)->toBe(Decision::AcceptedPoster)
        ->and($this->first->fresh()?->decision_notified_at)->toBeNull()
        ->and($this->first->fresh()?->decisions()->count())->toBe(2);
});

it('shows the decision and its history on the submission view', function () {
    livewire(ConferenceRanking::class, ['record' => $this->conference->getRouteKey()])
        ->callTableAction('decide', $this->first, ['decision' => Decision::Waitlisted->value, 'note' => 'Second round.']);

    livewire(ViewSubmission::class, ['record' => $this->first->getRouteKey()])
        ->assertSee(Decision::Waitlisted->getLabel())
        ->assertSee('Second round.')
        ->assertSee($this->user->name);
});

it('refuses a decision to somebody with no role in this organization', function () {
    $outsider = User::factory()->create();
    $otherOrganization = Organization::factory()->approved()->create();
    $otherOrganization->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider);
    bootOrganizerPanel($otherOrganization);

    expect(Gate::forUser($outsider)->allows('decide', $this->first))->toBeFalse();

    // And the page itself is a 404, which ConferenceRankingTest already pins;
    // this is the second gate, on the action rather than on the route.
    actingAs($this->user);
    bootOrganizerPanel($this->organization);

    $theirs = withoutTenant(fn (): Submission => Submission::factory()->submitted()->create());

    expect(Gate::forUser($this->user)->allows('decide', $theirs))->toBeFalse();
});

it('refuses to decide a withdrawn abstract even from the panel', function () {
    // The action is hidden for a withdrawn row - but the row is not on the
    // ranking at all (RankedSubmissions), so this pins the gate that matters:
    // the action re-checks the blockers before writing.
    $this->second->forceFill(['status' => SubmissionStatus::Withdrawn, 'withdrawn_at' => now()])->save();

    expect(app(ApplyDecision::class)
        ->blockers($this->second->fresh() ?? $this->second, Decision::AcceptedOral))
        ->not->toBe([]);
});
