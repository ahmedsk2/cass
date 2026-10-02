<?php

declare(strict_types=1);

use App\Actions\Organizations\UpdateOrganizationProfile;
use App\Contracts\DnsResolver;
use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Filament\Admin\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomDomainVerified;
use App\Support\Domains\FakeDnsResolver;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

/**
 * Spec section 4's "Manage organization profile, branding, domain" cell for the
 * platform admin. The form is the organizer's own (App\Filament\Schemas\
 * OrganizationProfileForm), so these cases are about who may use it from the
 * admin panel and what it does to the row, the disk and the audit log - not a
 * second copy of the organizer page's field-by-field tests.
 */
beforeEach(function () {
    Storage::fake('branding');
    Notification::fake();

    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);
    config()->set('cass.domains.cname_target', 'cass.towardpcc.com');

    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->owner = User::factory()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
});

/** The domain actions live inside the form schema, keyed exactly as on the organizer page. */
function adminDomainAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('custom_domain_actions');
}

it('registers an edit page and links to it from the view page', function () {
    expect(array_keys(OrganizationResource::getPages()))->toBe(['index', 'view', 'edit']);

    livewire(ViewOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->assertActionVisible('edit');

    get(OrganizationResource::getUrl('edit', ['record' => $this->organization], panel: 'admin'))
        ->assertOk()
        ->assertSee('Alpha Society')
        ->assertSee(__('domain.section.heading'));
});

it('saves the profile, the colours and a logo, and logs the admin as the causer', function () {
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm([
            'name' => 'Alpha Pediatric Society',
            'primary_color' => '#0F4C8A',
            'accent_color' => '#0B5FA5',
            'logo_path' => UploadedFile::fake()->image('logo.png', 400, 200),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->organization->refresh();

    expect($this->organization->name)->toBe('Alpha Pediatric Society')
        ->and($this->organization->primary_color)->toBe('#0F4C8A')
        ->and($this->organization->logo_path)->not->toBeNull();

    Storage::disk('branding')->assertExists((string) $this->organization->logo_path);

    $entry = Activity::query()->where('description', 'organization.profile_updated')->sole();

    expect($entry->causer_id)->toBe($this->admin->getKey())
        ->and($entry->subject_id)->toBe($this->organization->getKey())
        ->and($entry->properties->get('changed'))->toContain('name', 'primary_color', 'logo_path');
});

it('still refuses a colour that fails contrast, from the admin page too', function () {
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm(['primary_color' => '#BFE0F7'])
        ->call('save')
        ->assertHasFormErrors(['primary_color']);
});

it('deletes the replaced logo from the branding disk once the row is saved', function () {
    Storage::disk('branding')->put('logos/old.png', 'old bytes');
    $this->organization->forceFill(['logo_path' => 'logos/old.png'])->save();

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        // The browser's file picker removes the stored file before it uploads
        // the new one. Filling the field alone would append the upload beside
        // the old path, and a single-file field saves the first.
        ->fillForm(['logo_path' => []])
        ->fillForm(['logo_path' => UploadedFile::fake()->image('new.png', 400, 200)])
        ->call('save')
        ->assertHasNoFormErrors();

    $new = (string) $this->organization->refresh()->logo_path;

    expect($new)->not->toBe('logos/old.png');
    Storage::disk('branding')->assertMissing('logos/old.png');
    Storage::disk('branding')->assertExists($new);
});

it('deletes the logo file when the logo is cleared', function () {
    Storage::disk('branding')->put('logos/old.png', 'old bytes');
    $this->organization->forceFill(['logo_path' => 'logos/old.png'])->save();

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm(['logo_path' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->organization->refresh()->logo_path)->toBeNull();
    Storage::disk('branding')->assertMissing('logos/old.png');
});

it('keeps the old logo file when the save fails', function () {
    Storage::disk('branding')->put('logos/old.png', 'old bytes');
    $this->organization->forceFill(['logo_path' => 'logos/old.png'])->save();

    // A write that fails: the row keeps pointing at the old file, so the old
    // file must still be there. Deleting before the save is the order this
    // action exists to avoid.
    Organization::updating(function (): void {
        throw new RuntimeException('The database refused the write.');
    });

    expect(fn () => app(UpdateOrganizationProfile::class)->handle($this->organization, ['logo_path' => null], $this->admin))
        ->toThrow(RuntimeException::class);

    expect(Organization::query()->whereKey($this->organization->getKey())->value('logo_path'))->toBe('logos/old.png');
    Storage::disk('branding')->assertExists('logos/old.png');
});

it('waits for an outer transaction to commit, and keeps the file when it rolls back', function () {
    Storage::disk('branding')->put('logos/old.png', 'old bytes');
    $this->organization->forceFill(['logo_path' => 'logos/old.png'])->save();

    // What either save() becomes the day a panel turns on
    // ->databaseTransactions(): the action's own transaction is then nested,
    // and its commit is not the one that matters.
    try {
        DB::transaction(function (): void {
            app(UpdateOrganizationProfile::class)->handle($this->organization, ['logo_path' => null], $this->admin);

            Storage::disk('branding')->assertExists('logos/old.png');

            throw new RuntimeException('The page failed after the action returned.');
        });
    } catch (RuntimeException) {
        // The rollback is the point.
    }

    expect(Organization::query()->whereKey($this->organization->getKey())->value('logo_path'))->toBe('logos/old.png');
    Storage::disk('branding')->assertExists('logos/old.png');
});

it('refuses a logo above 16 megapixels and accepts one at exactly 16', function () {
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('huge.png', blankPng(4001, 4000))])
        ->call('save')
        ->assertHasFormErrors(['logo_path']);

    expect($this->organization->refresh()->logo_path)->toBeNull();

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('edge.png', blankPng(4000, 4000))])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->organization->refresh()->logo_path)->not->toBeNull();
});

it('edits one organization and leaves another untouched', function () {
    $other = Organization::factory()->approved()->create([
        'name' => 'Beta Society',
        'primary_color' => '#123456',
    ]);
    Storage::disk('branding')->put('logos/beta.png', 'beta bytes');
    $other->forceFill(['logo_path' => 'logos/beta.png'])->save();
    $before = $other->fresh()?->getAttributes();

    Storage::disk('branding')->put('logos/alpha.png', 'alpha bytes');
    $this->organization->forceFill(['logo_path' => 'logos/alpha.png'])->save();

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->fillForm(['logo_path' => []])
        ->fillForm([
            'name' => 'Alpha Renamed',
            'primary_color' => '#0F4C8A',
            'logo_path' => UploadedFile::fake()->image('new.png', 400, 200),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($other->fresh()?->getAttributes())->toBe($before);
    Storage::disk('branding')->assertExists('logos/beta.png');
    Storage::disk('branding')->assertMissing('logos/alpha.png');
});

it('refuses a logo path that belongs to another organization, so the cleanup can never reach it', function () {
    $other = Organization::factory()->approved()->create();
    Storage::disk('branding')->put('logos/beta.png', 'beta bytes');
    $other->forceFill(['logo_path' => 'logos/beta.png'])->save();

    // A hand-edited Livewire payload naming another organization's file.
    // FileUpload::preventFilePathTampering() refuses any stored path that is
    // not the record's own, so the row can never come to point at it - and so
    // the next replacement can never delete it.
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->set('data.logo_path', ['tampered' => 'logos/beta.png'])
        ->call('save')
        ->assertHasFormErrors(['logo_path']);

    expect($this->organization->refresh()->logo_path)->toBeNull();
    Storage::disk('branding')->assertExists('logos/beta.png');
});

it('never deletes a logo file another organization still points at', function () {
    // Rows written before this plan were never checked for tampering, so the
    // action re-checks at delete time rather than trusting the form alone.
    Storage::disk('branding')->put('logos/shared.png', 'bytes');
    $other = Organization::factory()->approved()->create();
    $other->forceFill(['logo_path' => 'logos/shared.png'])->save();
    $this->organization->forceFill(['logo_path' => 'logos/shared.png'])->save();

    app(UpdateOrganizationProfile::class)->handle($this->organization, ['logo_path' => null], $this->admin);

    expect($this->organization->fresh()?->logo_path)->toBeNull();
    Storage::disk('branding')->assertExists('logos/shared.png');
});

it('never deletes another organization\'s logo through another spelling of its path', function () {
    // The disk normalises a path before it deletes, so 'logos/./beta.png'
    // would remove 'logos/beta.png' - a file the exact-string row check above
    // never matches. Only a row written before this release can hold such a
    // spelling; DeleteOrganizationLogo::isCanonical() refuses every path
    // Filament would not have written.
    Storage::disk('branding')->put('logos/beta.png', 'beta bytes');
    $other = Organization::factory()->approved()->create();
    $other->forceFill(['logo_path' => 'logos/beta.png'])->save();
    $this->organization->forceFill(['logo_path' => 'logos/./beta.png'])->save();

    app(UpdateOrganizationProfile::class)->handle($this->organization, ['logo_path' => null], $this->admin);

    expect($this->organization->fresh()?->logo_path)->toBeNull();
    Storage::disk('branding')->assertExists('logos/beta.png');
});

it('claims, verifies and releases a custom domain for the organization, naming the admin in the log', function () {
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction(adminDomainAction('claimCustomDomain'), ['domain' => 'abstracts.example.org'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $this->organization->refresh();
    expect($this->organization->custom_domain)->toBe('abstracts.example.org');

    $this->dns->set('_cass-verify.abstracts.example.org', [(string) $this->organization->custom_domain_token]);

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction(adminDomainAction('verifyCustomDomain'))
        ->assertHasNoActionErrors();

    expect($this->organization->fresh()?->hasVerifiedCustomDomain())->toBeTrue();
    Notification::assertSentTo($this->admin, CustomDomainVerified::class);

    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction(adminDomainAction('releaseCustomDomain'))
        ->assertNotified();

    expect($this->organization->fresh()?->custom_domain)->toBeNull();

    $causers = Activity::query()
        ->whereIn('description', [
            'organization.custom_domain_claimed',
            'organization.custom_domain_verified',
            'organization.custom_domain_released',
        ])
        ->pluck('causer_id')
        ->unique()
        ->values()
        ->all();

    expect($causers)->toBe([$this->admin->getKey()]);
});

it('refuses the edit page to an organization owner', function () {
    actingAs($this->owner)
        ->get(OrganizationResource::getUrl('edit', ['record' => $this->organization], panel: 'admin'))
        ->assertForbidden();

    // Mounted directly, past the panel middleware: OrganizationPolicy::update()
    // says yes to this owner for their own organization, so it is the
    // resource's canAccess() that has to answer no.
    livewire(EditOrganization::class, ['record' => $this->organization->getRouteKey()])->assertForbidden();

    expect($this->organization->fresh()?->name)->toBe('Alpha Society');
});
