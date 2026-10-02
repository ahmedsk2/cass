<?php

declare(strict_types=1);

use App\Actions\Conferences\PurgeConference;
use App\Actions\Organizations\DeleteOrganizationLogo;
use App\Actions\Organizations\PurgeOrganization;
use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/**
 * The hard purge and the public `branding` disk. PurgeOrganization deleted the
 * private-disk objects behind an organization's abstracts but never its logo,
 * which stayed reachable at /storage/branding/logos/... for anybody holding the
 * URL - and every email the organization ever sent carries that URL.
 */
beforeEach(function () {
    Storage::fake('branding');
    Storage::fake('local');

    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    Storage::disk('branding')->put('logos/alpha.png', 'alpha bytes');
    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->organization->forceFill(['logo_path' => 'logos/alpha.png'])->save();
});

it('shows the logo in the purge modal and deletes it with the organization, and nobody else\'s', function () {
    Storage::disk('branding')->put('logos/beta.png', 'beta bytes');
    $other = Organization::factory()->approved()->create();
    $other->forceFill(['logo_path' => 'logos/beta.png'])->save();

    livewire(ListOrganizations::class)
        ->removeTableFilter('status')
        ->mountTableAction('purge', $this->organization)
        ->assertMountedActionModalSeeHtml('<strong>1</strong> '.__('admin.purge.logo'))
        ->setTableActionData(['confirmation' => (string) $this->organization->slug])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        // One row (the organization; it has nothing else) and one file (the
        // logo): the notification counts a logo as a file, not as a row.
        ->assertNotified(__('admin.purge.done', ['name' => 'Alpha Society', 'rows' => '1', 'files' => '1']));

    expect(Organization::withTrashed()->whereKey($this->organization->getKey())->exists())->toBeFalse()
        ->and($other->fresh()?->logo_path)->toBe('logos/beta.png');

    Storage::disk('branding')->assertMissing('logos/alpha.png');
    Storage::disk('branding')->assertExists('logos/beta.png');
});

it('reports the logo in the run and the preview alike', function () {
    $preview = app(PurgeOrganization::class)->preview($this->organization);
    $counts = app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    expect($preview['branding files'])->toBe(1)
        ->and($counts['branding files'])->toBe(1)
        ->and($counts['private files'])->toBe(0);
});

it('keeps the logo when the purge does not commit, and deletes it when the retry does', function () {
    // The last statement of the purge's transaction fails. Everything rolls
    // back, so the row still points at its logo, and the file has to be there.
    $refuse = true;

    DB::listen(function (QueryExecuted $query) use (&$refuse): void {
        if ($refuse && preg_match('/^delete from [`"]organizations[`"]/i', $query->sql) === 1) {
            throw new RuntimeException('The database refused the delete.');
        }
    });

    expect(fn () => app(PurgeOrganization::class)->handle($this->organization, $this->admin))
        ->toThrow(RuntimeException::class);

    expect(Organization::query()->whereKey($this->organization->getKey())->value('logo_path'))->toBe('logos/alpha.png');
    Storage::disk('branding')->assertExists('logos/alpha.png');

    // The operator retries and the database lets it through this time.
    $refuse = false;
    app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    Storage::disk('branding')->assertMissing('logos/alpha.png');
});

it('keeps a logo file another organization still points at, and previews it as kept', function () {
    // A path two rows share can only come from a hand-edited request before
    // the profile form refused one (Task 3 decision 5). The purge must not
    // take the other organization's logo with it.
    $other = Organization::factory()->approved()->create();
    $other->forceFill(['logo_path' => 'logos/alpha.png'])->save();

    expect(app(PurgeOrganization::class)->preview($this->organization)['branding files'])->toBe(0);

    $counts = app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    expect($counts['branding files'])->toBe(0);
    Storage::disk('branding')->assertExists('logos/alpha.png');
});

it('keeps another organization\'s logo when the purged row spells its path another way', function () {
    // The disk would delete 'logos/./beta.png' as 'logos/beta.png', which the
    // exact-string row check never matches. DeleteOrganizationLogo refuses
    // any spelling Filament does not write (Task 3 decision 5).
    Storage::disk('branding')->put('logos/beta.png', 'beta bytes');
    $other = Organization::factory()->approved()->create();
    $other->forceFill(['logo_path' => 'logos/beta.png'])->save();
    $this->organization->forceFill(['logo_path' => 'logos/./beta.png'])->save();

    expect(app(PurgeOrganization::class)->preview($this->organization)['branding files'])->toBe(0);

    $counts = app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    expect($counts['branding files'])->toBe(0);
    Storage::disk('branding')->assertExists('logos/beta.png');
});

it('reports nothing and throws nothing for a logo path that climbs out of the disk', function () {
    // The disk throws PathTraversalDetected for this path, after the purge's
    // commit, and nothing catches it. DeleteOrganizationLogo never hands it to
    // the disk, and the preview predicts the same zero.
    $this->organization->forceFill(['logo_path' => 'logos/../../x'])->save();

    // \z, not $: PCRE's $ also matches before a final newline, and the disk
    // throws CorruptedPathDetected on a control character.
    expect(DeleteOrganizationLogo::isCanonical("logos/x.png\n"))->toBeFalse();

    expect(app(PurgeOrganization::class)->preview($this->organization)['branding files'])->toBe(0);

    $counts = app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    expect($counts['branding files'])->toBe(0)
        ->and(Organization::withTrashed()->whereKey($this->organization->getKey())->exists())->toBeFalse();
});

it('leaves the logo alone when one conference is purged, and takes it with the organization', function () {
    // PurgeConference is the other purge, and the organization outlives it.
    $conference = Conference::factory()->for($this->organization)->create();

    app(PurgeConference::class)->handle($conference, $this->admin);

    expect($this->organization->fresh()?->logo_path)->toBe('logos/alpha.png');
    Storage::disk('branding')->assertExists('logos/alpha.png');

    app(PurgeOrganization::class)->handle($this->organization, $this->admin);

    Storage::disk('branding')->assertMissing('logos/alpha.png');
});

it('deletes the demo organization logo on cass:demo-reset', function () {
    // cass:demo-reset is PurgeDemoOrganization, which is PurgeOrganization
    // narrowed to the is_demo row - so the logo goes the same way. The seeder
    // never sets one; an owner trying the branding screen on the demo would.
    Storage::disk('branding')->put('logos/demo.png', 'demo bytes');
    $demo = Organization::factory()->approved()->create(['slug' => 'demo-society']);
    $demo->forceFill(['is_demo' => true, 'logo_path' => 'logos/demo.png'])->save();

    $this->artisan('cass:demo-reset', ['--confirm' => true])
        ->expectsOutputToContain('branding files')
        ->assertSuccessful();

    expect(Organization::withTrashed()->whereKey($demo->getKey())->exists())->toBeFalse();
    Storage::disk('branding')->assertMissing('logos/demo.png');
});
