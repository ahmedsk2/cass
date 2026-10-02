<?php

declare(strict_types=1);

use App\Enums\ReviewerStatus;
use App\Filament\Reviewer\Pages\EditProfile;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->closed()->create(['name' => 'Alpha Annual Meeting']);
    $this->second = Conference::factory()->for($this->organization)->closed()->create(['name' => 'Alpha Winter School']);

    $this->reviewer = User::factory()->create(['name' => 'Dr Omar Khan']);
    $this->row = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);
    $this->secondRow = ConferenceReviewer::factory()->for($this->second)->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);

    actingAs($this->reviewer);
    bootReviewerPanel();
});

it('is the reviewer panel\'s profile page and keeps Filament\'s own profile form and two-factor section', function () {
    expect(Filament::getPanel('reviewer')->getProfilePage())->toBe(EditProfile::class)
        // The other two panels keep Filament's page: the affiliation is a
        // reviewer's statement about a conference, and neither panel has one.
        ->and(Filament::getPanel('organizer')->getProfilePage())->not->toBe(EditProfile::class);

    // A real request, so the page is rendered through the panel's middleware
    // and layout, not only as a component.
    $response = get('/review/profile')
        ->assertOk()
        ->assertSee(__('filament-panels::auth/pages/edit-profile.form.name.label'))
        ->assertSee(__('filament-panels::auth/pages/edit-profile.multi_factor_authentication.label'))
        ->assertSee(__('reviewer.affiliation.heading'))
        ->assertSee('Alpha Annual Meeting')
        // Filament's fallback aria-label for the table, derived from the
        // model's class name - English that no language file could reach.
        ->assertDontSee('conference reviewers');

    // A new panel page under Plan 6's CSP: every INLINE <script> and <style>
    // must carry the request's nonce. A <script src> is same-origin and is
    // allowed by script-src 'self' (app/Http/Middleware/ContentSecurityPolicy.php:70).
    preg_match_all('/<(?:script|style)\b(?![^>]*\b(?:nonce|src)=)[^>]*>/i', (string) $response->getContent(), $unnonced);

    expect($unnonced[0])->toBe([]);
});

it('restates exactly what Filament\'s own content() renders, so an upgrade that changes it is a red test', function () {
    // EditProfile::content() lists the parent's two components by hand and
    // appends the affiliations. If Filament adds a third, the override would
    // silently drop it. When this fails: read the new vendor method, restate
    // it in app/Filament/Reviewer/Pages/EditProfile.php::content(), then
    // update the string below in the same commit.
    $method = new ReflectionMethod(BaseEditProfile::class, 'content');
    $lines = array_slice(
        file((string) $method->getFileName()) ?: [],
        (int) $method->getStartLine() - 1,
        (int) $method->getEndLine() - (int) $method->getStartLine() + 1,
    );

    expect((string) preg_replace('/\s+/', '', implode('', $lines)))->toBe(
        'publicfunctioncontent(Schema$schema):Schema{return$schema->components(['
        .'$this->getFormContentComponent(),'
        .'...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),'
        .']);}'
    );
});

it('lists one row per conference the reviewer is active in, with its organization', function () {
    livewire(EditProfile::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->row, $this->secondRow])
        ->assertSee('Alpha Society')
        ->assertSee('Old Hospital');
});

it('prefills the current value and changes it for one conference only', function () {
    livewire(EditProfile::class)
        ->mountTableAction('editAffiliation', $this->row)
        ->assertTableActionDataSet(['affiliation' => 'Old Hospital']);

    // A fresh component: calling on the one above would nest the second
    // mount inside the first and look for a modal action of the same name.
    livewire(EditProfile::class)
        ->callTableAction('editAffiliation', $this->row, data: ['affiliation' => '  New Hospital  '])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('reviewer.affiliation.saved'));

    expect($this->row->fresh()?->affiliation)->toBe('New Hospital')
        ->and($this->secondRow->fresh()?->affiliation)->toBe('Old Hospital')
        ->and(Activity::query()->where('description', 'reviewer.affiliation_changed')->sole()->causer_id)
        ->toBe($this->reviewer->id);
});

it('refuses more than 255 characters in the form, as the organizer\'s invite form does', function () {
    livewire(EditProfile::class)
        ->callTableAction('editAffiliation', $this->row, data: ['affiliation' => str_repeat('a', 256)])
        ->assertHasTableActionErrors(['affiliation' => 'max']);

    expect($this->row->fresh()?->affiliation)->toBe('Old Hospital');
});

it('neither lists nor changes another reviewer\'s row in the same conference', function () {
    $theirs = ConferenceReviewer::factory()->for($this->conference)->create(['affiliation' => 'Their Hospital']);

    livewire(EditProfile::class)->assertCanNotSeeTableRecords([$theirs]);

    // A forged key: the table resolves it through its own query
    // (vendor/filament/tables/src/Concerns/HasRecords.php:194-205), finds
    // nothing, and Filament refuses to run the action at all
    // (vendor/filament/actions/src/Concerns/InteractsWithActions.php:706-711).
    // The exact class, not Throwable: a TypeError swallowed here would be a
    // bug passing as a refusal.
    expect(fn () => livewire(EditProfile::class)
        ->callTableAction('editAffiliation', $theirs, data: ['affiliation' => 'Hijacked']))
        ->toThrow(ActionNotResolvableException::class, 'no longer exists');

    expect($theirs->fresh()?->affiliation)->toBe('Their Hospital');
});

it('neither lists nor changes the reviewer\'s own removed row in another organization\'s conference', function () {
    $theirOrganization = Organization::factory()->approved()->create(['name' => 'Beta Society']);
    $theirConference = Conference::factory()->for($theirOrganization)->closed()->create(['name' => 'Beta Congress']);
    $removedThere = ConferenceReviewer::factory()->for($theirConference)->removed()->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);

    livewire(EditProfile::class)
        ->assertCanNotSeeTableRecords([$removedThere])
        ->assertDontSee('Beta Congress')
        ->assertDontSee('Beta Society');

    expect(fn () => livewire(EditProfile::class)
        ->callTableAction('editAffiliation', $removedThere, data: ['affiliation' => 'New Hospital']))
        ->toThrow(ActionNotResolvableException::class, 'no longer exists');

    expect($removedThere->fresh()?->affiliation)->toBe('Old Hospital');
});

it('does not list a conference that has been deleted', function () {
    $this->second->delete();

    livewire(EditProfile::class)
        ->assertCanSeeTableRecords([$this->row])
        ->assertCanNotSeeTableRecords([$this->secondRow]);
});

it('drops a conference from the list the moment the organizer removes the reviewer from it', function () {
    $this->row->forceFill(['status' => ReviewerStatus::Removed, 'removed_at' => now()])->save();

    livewire(EditProfile::class)
        ->assertCanSeeTableRecords([$this->secondRow])
        ->assertCanNotSeeTableRecords([$this->row]);

    expect(fn () => livewire(EditProfile::class)
        ->callTableAction('editAffiliation', $this->row, data: ['affiliation' => 'New Hospital']))
        ->toThrow(ActionNotResolvableException::class, 'no longer exists');

    expect($this->row->fresh()?->affiliation)->toBe('Old Hospital');
});

it('keeps a reviewer removed from every conference off the page altogether', function () {
    ConferenceReviewer::query()->where('user_id', $this->reviewer->id)
        ->update(['status' => ReviewerStatus::Removed->value, 'removed_at' => now()]);

    // User::canAccessPanel('reviewer') is isActiveReviewer(), so the panel's
    // Authenticate middleware answers before the page is built.
    actingAs($this->reviewer->fresh())->get('/review/profile')->assertForbidden();
});
