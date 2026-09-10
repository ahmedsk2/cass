<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('redirects guests from the organizer panel to its login page', function () {
    get('/org')->assertRedirect('/org/login');
});

it('lets a verified member open their organization dashboard', function () {
    $org = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $user = User::factory()->create();
    $org->addMember($user, OrganizationRole::Owner);

    actingAs($user)->get("/org/{$org->slug}")->assertOk()->assertSee('Alpha Society');
});

it('blocks a member of another organization from a tenant they do not belong to', function () {
    $mine = Organization::factory()->approved()->create();
    $theirs = Organization::factory()->approved()->create();
    $user = User::factory()->create();
    $mine->addMember($user, OrganizationRole::Owner);

    actingAs($user)->get("/org/{$theirs->slug}")->assertNotFound();
});

it('refuses unverified users at the organizer panel', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->unverified()->create();
    $org->addMember($user, OrganizationRole::Owner);

    // User::canAccessPanel() requires hasVerifiedEmail() for the organizer
    // panel, so Filament's own Authenticate middleware aborts with 403
    // before the request ever reaches the panel's emailVerification()
    // redirect flow.
    actingAs($user)->get("/org/{$org->slug}")->assertRedirect('/org/email-verification/prompt');
});

it('keeps non-admins out of the admin panel', function () {
    $user = User::factory()->create();
    actingAs($user)->get('/admin')->assertForbidden();
});

it('lets a platform admin into the admin panel', function () {
    $admin = User::factory()->platformAdmin()->create();
    actingAs($admin)->get('/admin')->assertOk();
});

it('shows a pending banner on the organizer dashboard until approval', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->addMember($user, OrganizationRole::Owner);

    actingAs($user)->get("/org/{$org->slug}")
        ->assertOk()
        ->assertSee('awaiting approval');
});
