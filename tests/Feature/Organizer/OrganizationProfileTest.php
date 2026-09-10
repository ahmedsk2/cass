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
