<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Organizer\Pages\Tenancy\EditOrganizationProfile;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('branding');
    $this->org = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->user = User::factory()->create();
    $this->org->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($this->org);
    Filament::bootCurrentPanel();
});

it('renders the profile page for a member', function () {
    get('/org/'.$this->org->slug.'/profile')->assertOk()->assertSee('Organization profile');
});

it('updates profile fields, colours and logo', function () {
    livewire(EditOrganizationProfile::class)
        ->fillForm([
            'name' => 'Alpha Pediatric Society',
            'website' => 'https://alpha.example.org',
            'contact_email' => 'office@alpha.example.org',
            'primary_color' => '#0F4C8A',
            'accent_color' => '#0B5FA5',
            'logo_path' => UploadedFile::fake()->image('logo.png', 400, 200),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->org->refresh();
    expect($this->org->name)->toBe('Alpha Pediatric Society')
        ->and($this->org->primary_color)->toBe('#0F4C8A')
        ->and($this->org->logo_path)->not->toBeNull();
    Storage::disk('branding')->assertExists($this->org->logo_path);
});

it('rejects a primary colour that fails contrast against white', function () {
    livewire(EditOrganizationProfile::class)
        ->fillForm(['primary_color' => '#BFE0F7', 'accent_color' => '#BFE0F7'])
        ->call('save')
        ->assertHasFormErrors(['primary_color', 'accent_color']);
});

it('does not let a member of another organization open this profile', function () {
    $other = Organization::factory()->approved()->create();
    $outsider = User::factory()->create();
    $other->addMember($outsider, OrganizationRole::Owner);

    actingAs($outsider)->get('/org/'.$this->org->slug.'/profile')->assertNotFound();
});

it('hides the profile page from plain members', function () {
    $member = User::factory()->create();
    $this->org->addMember($member, OrganizationRole::Member);

    // Filament 5.8 resolves a false canView() into a 404 (not a 403) for
    // tenant profile pages, the same as its tenant-mismatch behaviour above.
    actingAs($member)->get('/org/'.$this->org->slug.'/profile')->assertNotFound();
});

it('publishes the contact address only when the organizer opts in', function () {
    // The factory leaves the colour columns to their database defaults, so the
    // tenant instance has to be read back before the form is filled partially.
    $this->org->refresh();

    expect($this->org->publish_contact_email)->toBeFalse();

    livewire(EditOrganizationProfile::class)
        ->fillForm([
            'contact_email' => 'abstracts@alpha.example.org',
            'publish_contact_email' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->org->refresh();
    expect($this->org->contact_email)->toBe('abstracts@alpha.example.org')
        ->and($this->org->publish_contact_email)->toBeTrue();
});

it('deletes the replaced logo from the branding disk and logs the organizer as the causer', function () {
    Storage::disk('branding')->put('logos/old.png', 'old bytes');
    // refresh(): the factory leaves the colours to their database defaults,
    // and the form below is filled partially (see the contact-address case).
    $this->org->forceFill(['logo_path' => 'logos/old.png'])->save();
    $this->org->refresh();

    livewire(EditOrganizationProfile::class)
        // The browser's file picker removes the stored file before it uploads
        // the new one; filling the field alone appends beside the old path.
        ->fillForm(['logo_path' => []])
        ->fillForm(['logo_path' => UploadedFile::fake()->image('new.png', 400, 200)])
        ->call('save')
        ->assertHasNoFormErrors();

    $new = (string) $this->org->refresh()->logo_path;

    expect($new)->not->toBe('logos/old.png');
    Storage::disk('branding')->assertMissing('logos/old.png');
    Storage::disk('branding')->assertExists($new);

    $entry = Activity::query()->where('description', 'organization.profile_updated')->sole();

    expect($entry->causer_id)->toBe($this->user->getKey())
        ->and($entry->properties->get('changed'))->toBe(['logo_path']);
});

it('refuses a logo above 16 megapixels at upload, with the reason', function () {
    // 4001 x 4000 is 16,004,000 pixels: one row over the line that
    // GenerateConferencePoster::logoDataUri() silently drops a logo at.
    $this->org->refresh();

    livewire(EditOrganizationProfile::class)
        ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('huge.png', blankPng(4001, 4000))])
        ->call('save')
        ->assertHasFormErrors(['logo_path'])
        ->assertSee(__('admin.organization.logo_too_large', ['width' => '4,001', 'height' => '4,000', 'max' => 16]));

    expect($this->org->refresh()->logo_path)->toBeNull();
});
