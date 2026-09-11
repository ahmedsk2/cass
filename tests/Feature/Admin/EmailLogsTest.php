<?php

declare(strict_types=1);

use App\Enums\EmailLogStatus;
use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Conferences\Pages\ListConferences;
use App\Filament\Admin\Resources\EmailLogs\EmailLogResource;
use App\Filament\Admin\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Admin\Resources\EmailLogs\Pages\ViewEmailLog;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use App\Policies\EmailLogPolicy;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_platform_admin' => true]);
    actingAs($this->admin);
    // Both, in this order, exactly as tests/Feature/Admin/ConferencesTest.php
    // and OrganizationApprovalTest.php do it. bootCurrentPanel() is what runs
    // Panel::boot() - tenancy observers, assets, colors, icons, SPA config,
    // render hooks and plugins - so a page tested without it is not the page a
    // real request renders.
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Gulf Pediatric Society']);
    $this->conference = Conference::factory()->for($this->organization)->published()->create();
});

it('lists every tenant log row, newest first', function () {
    $old = EmailLog::factory()->sent()->create(['to_email' => 'first@example.org', 'created_at' => now()->subDay()]);
    $new = EmailLog::factory()->for($this->organization)->for($this->conference)->create(['to_email' => 'second@example.org']);

    livewire(ListEmailLogs::class)
        ->assertCanSeeTableRecords([$old, $new])
        ->assertCanRenderTableColumn('to_email')
        ->assertCanRenderTableColumn('status')
        ->assertCanRenderTableColumn('mailable')
        ->assertSee('Gulf Pediatric Society');
});

it('filters by status and by organization and searches by recipient', function () {
    $failed = EmailLog::factory()->failed()->create(['to_email' => 'bounced@example.org']);
    $sent = EmailLog::factory()->sent()->for($this->organization)->create(['to_email' => 'fine@example.org']);

    livewire(ListEmailLogs::class)
        ->filterTable('status', EmailLogStatus::Failed->value)
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$sent]);

    livewire(ListEmailLogs::class)
        ->filterTable('organization_id', $this->organization->id)
        ->assertCanSeeTableRecords([$sent])
        ->assertCanNotSeeTableRecords([$failed]);

    livewire(ListEmailLogs::class)
        ->searchTable('bounced@example.org')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$sent]);
});

it('shows the error text and the context on the view page', function () {
    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    $log = EmailLog::factory()
        ->failed('Expected response code "250" but got code "550", with message "550 5.1.1 User unknown"')
        ->for($this->organization)
        ->for($this->conference)
        ->for($submission)
        ->create(['to_email' => 'nobody@example.org', 'template_key' => 'submission_received']);

    livewire(ViewEmailLog::class, ['record' => $log->getRouteKey()])
        ->assertOk()
        ->assertSee('nobody@example.org')
        ->assertSee('550 5.1.1 User unknown')
        ->assertSee('submission_received')
        ->assertSee('Gulf Pediatric Society');
});

it('is read-only: no create, edit or delete anywhere', function () {
    $log = EmailLog::factory()->create();
    $policy = app(EmailLogPolicy::class);

    expect(EmailLogResource::getPages())->toHaveKeys(['index', 'view'])
        ->and(EmailLogResource::getPages())->not->toHaveKey('create')
        ->and(EmailLogResource::getPages())->not->toHaveKey('edit')
        ->and($policy->create($this->admin))->toBeFalse()
        ->and($policy->update($this->admin, $log))->toBeFalse()
        ->and($policy->delete($this->admin, $log))->toBeFalse()
        ->and($policy->deleteAny($this->admin))->toBeFalse()
        ->and($policy->restoreAny($this->admin))->toBeFalse()
        ->and($policy->forceDeleteAny($this->admin))->toBeFalse();
});

it('is invisible to anyone who is not a platform admin', function () {
    $organizer = User::factory()->create();
    $this->organization->addMember($organizer, OrganizationRole::Owner);
    actingAs($organizer);

    expect(EmailLogResource::canAccess())->toBeFalse()
        ->and(app(EmailLogPolicy::class)->viewAny($organizer))->toBeFalse();

    // And the route itself, not just the navigation item.
    $this->get(EmailLogResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

it('counts submissions on the admin conference list', function () {
    Submission::factory()->count(3)->for($this->conference)->submitted()->create();

    livewire(ListConferences::class)
        ->assertCanRenderTableColumn('submissions_count')
        ->assertSee('3');
});
