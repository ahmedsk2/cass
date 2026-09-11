<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Livewire\Public\RegisterOrganization;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationRegistered;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(fn () => Notification::fake());

function validRegistration(): array
{
    return [
        'name' => 'Dr Sara Al-Otaibi',
        'email' => 'sara@example.org',
        'password' => 'Correct-Horse-Battery-9',
        'password_confirmation' => 'Correct-Horse-Battery-9',
        'organization_name' => 'Saudi Pediatric Society',
        'organization_type' => 'society',
        'country' => 'SA',
        'website' => 'https://sps.example.org',
        'purpose' => 'Annual pediatric symposium abstract collection.',
        'terms' => true,
    ];
}

it('renders the registration page', function () {
    get('/register')->assertOk()->assertSee('Register your organization');
});

it('creates the user, a pending organization, an owner membership and notifies admins', function () {
    $admin = User::factory()->platformAdmin()->create();

    livewire(RegisterOrganization::class)
        ->set(validRegistration())
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect('/org');

    $user = User::query()->where('email', 'sara@example.org')->firstOrFail();
    $org = Organization::query()->where('slug', 'saudi-pediatric-society')->firstOrFail();

    // The registering owner's address is a personal login, and the public
    // conference page can publish contact_email, so registration leaves it
    // empty for the organizer to fill in with an address meant to be read.
    expect($org->status)->toBe(OrganizationStatus::Pending)
        ->and($org->contact_email)->toBeNull()
        ->and($user->roleIn($org))->toBe(OrganizationRole::Owner)
        ->and(auth()->id())->toBe($user->id);

    Notification::assertSentTo($user, QueuedVerifyEmail::class);
    Notification::assertSentTo($admin, OrganizationRegistered::class);
});

it('rejects a duplicate email and a weak password', function () {
    User::factory()->create(['email' => 'sara@example.org']);

    livewire(RegisterOrganization::class)
        ->set(validRegistration())
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('register')
        ->assertHasErrors(['email', 'password']);

    expect(Organization::query()->count())->toBe(0);
});

it('silently drops submissions that fill the honeypot', function () {
    livewire(RegisterOrganization::class)
        ->set(validRegistration())
        ->set('website_confirm', 'bot')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect(User::query()->count())->toBe(0);
});

it('requires the terms checkbox', function () {
    livewire(RegisterOrganization::class)
        ->set(validRegistration())
        ->set('terms', false)
        ->call('register')
        ->assertHasErrors(['terms']);
});

it('rate limits repeated registrations from one client', function () {
    foreach (range(1, 5) as $i) {
        RateLimiter::hit('register:127.0.0.1', 600);
    }

    livewire(RegisterOrganization::class)
        ->set(validRegistration())
        ->call('register')
        ->assertHasErrors(['email']);

    expect(User::query()->count())->toBe(0);
});

it('does not count validation failures against the limit', function () {
    livewire(RegisterOrganization::class)
        ->set(validRegistration())
        ->set('terms', false)
        ->call('register')
        ->assertHasErrors(['terms']);

    expect(RateLimiter::attempts('register:127.0.0.1'))->toBe(0);
});

it('lowercases the email before saving', function () {
    livewire(RegisterOrganization::class)
        ->set(validRegistration())
        ->set('email', 'Sara@Example.ORG')
        ->call('register')
        ->assertHasNoErrors();

    expect(User::query()->where('email', 'sara@example.org')->exists())->toBeTrue();
});
