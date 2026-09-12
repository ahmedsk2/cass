<?php

declare(strict_types=1);

use App\Actions\Organizations\RejectOrganization;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Filament\Admin\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationRegistered;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Notification::fake();
    // The two approval letters are TemplatedMail now, not notifications: the
    // copy lives in lang/en/mail.php and a transport failure marks its own
    // email_logs row.
    Mail::fake();
    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();
});

it('lists organizations with their status', function () {
    $pending = Organization::factory()->create(['name' => 'Pending Society']);
    $approved = Organization::factory()->approved()->create(['name' => 'Approved Society']);

    livewire(ListOrganizations::class)
        ->assertOk()
        ->removeTableFilter('status')
        ->assertCanSeeTableRecords([$pending, $approved])
        ->assertSee('Pending Society')
        ->assertSee('Approved Society');
});

it('shows only pending organizations by default', function () {
    $pending = Organization::factory()->create(['name' => 'Pending Society']);
    $approved = Organization::factory()->approved()->create(['name' => 'Approved Society']);

    livewire(ListOrganizations::class)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$approved]);
});

it('approves a pending organization and emails the owner', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $org->addMember($owner, OrganizationRole::Owner);

    livewire(ListOrganizations::class)
        ->callTableAction('approve', $org)
        ->assertNotified();

    $org->refresh();
    expect($org->status)->toBe(OrganizationStatus::Approved)
        ->and($org->approved_by)->toBe($this->admin->id)
        ->and($org->approved_at)->not->toBeNull();

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo($owner->email));

    $log = EmailLog::query()->where('to_email', $owner->email)->firstOrFail();

    expect($log->template_key)->toBe('organization_approved')
        ->and($log->mailable)->toBe(TemplatedMail::class)
        ->and($log->organization_id)->toBe($org->id)
        // No conference exists at approval time, and email_logs.conference_id
        // is nullable for exactly this message.
        ->and($log->conference_id)->toBeNull();
});

it('rejects with a reason and emails the owner', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $org->addMember($owner, OrganizationRole::Owner);

    livewire(ListOrganizations::class)
        ->callTableAction('reject', $org, data: ['reason' => 'We could not verify this society.'])
        ->assertNotified();

    $org->refresh();
    expect($org->status)->toBe(OrganizationStatus::Suspended)
        ->and($org->status_reason)->toBe('We could not verify this society.');

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo($owner->email));

    expect(EmailLog::query()->where('to_email', $owner->email)->firstOrFail()->template_key)
        ->toBe('organization_rejected');
});

it('tells an organization that was approved that it is suspended, not that it was refused', function () {
    // RejectOrganization sent two different letters off one $wasApproved flag
    // before this plan. A union-typed context carries no room for a boolean, so
    // the branch is a second template key - and "we could not approve you" to an
    // organization that WAS approved is the regression this case exists to stop.
    $org = Organization::factory()->approved()->create();
    $owner = User::factory()->create();
    $org->addMember($owner, OrganizationRole::Owner);

    livewire(ListOrganizations::class)
        ->removeTableFilter('status')
        ->callTableAction('reject', $org, data: ['reason' => 'Repeated policy violations.'])
        ->assertNotified();

    expect(EmailLog::query()->where('to_email', $owner->email)->firstOrFail()->template_key)
        ->toBe('organization_suspended');
});

it('still tells a rejected owner why', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $organization->addMember($owner, OrganizationRole::Owner);

    app(RejectOrganization::class)
        ->handle($organization, User::factory()->platformAdmin()->create(), 'Not a real society');

    Mail::assertQueued(TemplatedMail::class,
        fn (TemplatedMail $mail): bool => str_contains($mail->body, 'Not a real society'));
});

it('requires a reason to reject', function () {
    $org = Organization::factory()->create();

    livewire(ListOrganizations::class)
        ->callTableAction('reject', $org, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect($org->refresh()->status)->toBe(OrganizationStatus::Pending);
});

it('opens the view page behind the URL Filament generates', function () {
    // Organization::getRouteKeyName() is the slug, so the resource has to
    // resolve the record by slug too: a resource that binds by id while the
    // row link prints a slug answers 404 on every View action.
    $org = Organization::factory()->create(['name' => 'Viewable Society']);

    get(ViewOrganization::getUrl(['record' => $org]))->assertOk()->assertSee('Viewable Society');
    get(OrganizationResource::getUrl('view', ['record' => $org]))->assertOk()->assertSee('Viewable Society');
});

it('points the registration notification at an admin page that opens', function () {
    $org = Organization::factory()->create(['name' => 'Notified Society']);
    $owner = User::factory()->create();

    $url = (new OrganizationRegistered($org, $owner))->toMail($this->admin)->actionUrl;

    get((string) $url)->assertOk()->assertSee('Notified Society');
});

it('is invisible to non-admins', function () {
    actingAs(User::factory()->create());
    get('/admin/organizations')->assertForbidden();
});

it('sends security headers on admin pages', function () {
    get('/admin/organizations')->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('refuses the approve action for a non-admin even when called directly', function () {
    $org = Organization::factory()->create();
    actingAs(User::factory()->create());

    livewire(ListOrganizations::class)->assertForbidden();

    expect($org->refresh()->status)->toBe(OrganizationStatus::Pending);
});

it('clears approval fields when an approved organization is rejected', function () {
    $org = Organization::factory()->approved()->create(['approved_by' => $this->admin->id]);

    livewire(ListOrganizations::class)
        ->removeTableFilter('status')
        ->callTableAction('reject', $org, data: ['reason' => 'Repeated policy violations.'])
        ->assertNotified();

    $org->refresh();
    expect($org->approved_at)->toBeNull()
        ->and($org->approved_by)->toBeNull()
        ->and($org->status)->toBe(OrganizationStatus::Suspended);
});
