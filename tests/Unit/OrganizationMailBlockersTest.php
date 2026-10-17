<?php

declare(strict_types=1);

use App\Actions\Organizations\InviteMember;
use App\Actions\Reviewers\InviteReviewer;
use App\Actions\Reviewers\InviteReviewerList;
use App\Actions\Reviews\SendReviewerReminders;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Exceptions\MemberChangeRefused;
use App\Exceptions\ReviewNotAcceptable;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ReviewerInvitation;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    $this->owner = User::factory()->create();
    $this->organization = Organization::factory()->approved()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    // In review, one reviewer behind, 07:00 in Riyadh seven days before the deadline: a reminder is due.
    $this->conference = Conference::factory()->for($this->organization)
        ->create(['status' => ConferenceStatus::Reviewing, 'timezone' => 'Asia/Riyadh', 'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC')]);
    ConferenceReviewer::factory()->for($this->conference)->create();
    Submission::factory()->for($this->conference)->submitted()->create();
    Carbon::setTestNow(Carbon::parse('2026-10-27 04:00:00', 'UTC'));
    $this->setStatus = fn (OrganizationStatus $status) => $this->organization->forceFill(['status' => $status])->save();
});

dataset('organization not approved', [
    'pending' => [OrganizationStatus::Pending, 'organization_pending'],
    'suspended' => [OrganizationStatus::Suspended, 'organization_suspended'],
]);

it('refuses a reviewer invitation, and a pasted list once, and sends nothing', function (OrganizationStatus $status, string $reason) {
    ($this->setStatus)($status);
    $conference = $this->conference->fresh();

    expect(app(InviteReviewer::class)->blockers($conference, 'new@example.org', $this->owner))->toBe([__('reviewer.errors.'.$reason)])
        ->and(fn () => app(InviteReviewer::class)->handle($conference, 'new@example.org', null, null, $this->owner))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.'.$reason))
        ->and(app(InviteReviewerList::class)->handle($conference, "a@example.org\nb@example.org", $this->owner))
        ->toBe(['invited' => 0, 'skipped' => [], 'errors' => [__('reviewer.errors.'.$reason)]])
        ->and(ReviewerInvitation::query()->count())->toBe(0)
        ->and(RateLimiter::attempts('invitation-send:'.$this->owner->getKey()))->toBe(0);
    Mail::assertNothingQueued();
})->with('organization not approved');

it('refuses a member invitation and sends nothing', function (OrganizationStatus $status, string $reason) {
    ($this->setStatus)($status);

    expect(app(InviteMember::class)->blockers($this->organization, 'new@example.org', OrganizationRole::Member, $this->owner))
        ->toBe([__('members.errors.'.$reason)])
        ->and(fn () => app(InviteMember::class)->handle($this->organization, 'new@example.org', OrganizationRole::Member, $this->owner))
        ->toThrow(MemberChangeRefused::class, __('members.errors.'.$reason))
        ->and(OrganizationInvitation::query()->count())->toBe(0)
        ->and(RateLimiter::attempts('invitation-send:'.$this->owner->getKey()))->toBe(0);
    Notification::assertNothingSent();
})->with('organization not approved');

it('refuses a manual reminder before it scans any reviewer queue, and sends nothing', function (OrganizationStatus $status, string $reason) {
    ($this->setStatus)($status);
    $conference = $this->conference->fresh();
    DB::enableQueryLog();

    // The queue scan is the only part of manualBlockers() that reads submissions.
    expect(app(SendReviewerReminders::class)->manualBlockers($conference))->toBe([__('reviewer.remind.errors.'.$reason)])
        ->and(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains((string) $query['query'], 'submissions')))->toBeEmpty();

    // The send itself refuses for the organization, before it claims the window.
    expect(fn () => app(SendReviewerReminders::class)->manual($conference, $this->owner))
        ->toThrow(ReviewNotAcceptable::class, __('reviewer.remind.errors.'.$reason))
        ->and($conference->fresh()?->reviewer_reminded_at)->toBeNull();
    Mail::assertNothingQueued();
})->with('organization not approved');

it('skips the automatic reminder without failing the hourly pass, and sends it once approved', function (OrganizationStatus $status) {
    ($this->setStatus)($status);

    expect(app(SendReviewerReminders::class)->automatic($this->conference->fresh()))->toBe(['sent' => 0, 'threshold' => null]);
    artisan('cass:reviewer-reminders')->assertExitCode(0);
    Mail::assertNothingQueued();

    ($this->setStatus)(OrganizationStatus::Approved);
    expect(app(SendReviewerReminders::class)->automatic($this->conference->fresh())['sent'])->toBe(1);
})->with('organization not approved');
