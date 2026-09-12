<?php

declare(strict_types=1);

use App\Enums\ReminderThreshold;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\CustomField;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewerInvitation;
use App\Models\ReviewerReminder;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\ShortLink;
use App\Models\ShortLinkVisit;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    config(['cass.admin_email' => 'owner@example.org']);

    $this->owner = User::factory()->platformAdmin()->create([
        'email' => 'owner@example.org',
        'name' => 'Demo Owner',
    ]);

    $this->artisan('cass:demo-seed', ['--stage' => 'decided', '--reviewer-password' => 'demo-password-1234'])
        ->assertSuccessful();

    $this->organization = Organization::query()->where('slug', 'demo-society')->firstOrFail();
    $this->conference = Conference::query()->where('slug', 'demo-2027')->firstOrFail();

    // Four rows the seeder does not create but an owner running the demo would:
    // an edited email template, a scan of the short link, a reminder the hourly
    // command would write, and an organization invitation. The purge has to
    // take all four.
    EmailTemplate::factory()->for($this->conference)->create();

    ShortLinkVisit::query()->forceCreate([
        'short_link_id' => $this->conference->shortLink()->firstOrFail()->getKey(),
        'visited_at' => now(),
    ]);

    ReviewerReminder::query()->forceCreate([
        'conference_id' => $this->conference->getKey(),
        'user_id' => User::query()->where('email', 'demo.reviewer3@example.com')->firstOrFail()->getKey(),
        'threshold' => ReminderThreshold::cases()[0]->value,
        'sent_at' => now(),
    ]);

    OrganizationInvitation::factory()->for($this->organization)->create();

    EmailLog::factory()->create([
        'organization_id' => $this->organization->getKey(),
        'conference_id' => $this->conference->getKey(),
    ]);
});

it('hard-deletes every row and every private file of the demo organization', function () {
    $paths = SubmissionFile::query()->pluck('path')->all();

    expect($paths)->toHaveCount(15);

    $this->artisan('cass:demo-reset', ['--confirm' => true])
        ->expectsOutputToContain('submissions')
        ->assertSuccessful();

    expect(Organization::withTrashed()->where('slug', 'demo-society')->exists())->toBeFalse()
        ->and(Conference::withTrashed()->count())->toBe(0)
        ->and(Submission::withTrashed()->count())->toBe(0)
        ->and(SubmissionAuthor::query()->count())->toBe(0)
        ->and(SubmissionFile::query()->count())->toBe(0)
        ->and(SubmissionDecision::query()->count())->toBe(0)
        ->and(Review::query()->count())->toBe(0)
        ->and(ReviewAnswer::query()->count())->toBe(0)
        ->and(ReviewAssignment::query()->count())->toBe(0)
        ->and(ReviewForm::query()->count())->toBe(0)
        ->and(ReviewQuestion::query()->count())->toBe(0)
        ->and(Track::query()->count())->toBe(0)
        ->and(CustomField::query()->count())->toBe(0)
        ->and(ConferenceReviewer::query()->count())->toBe(0)
        ->and(ReviewerInvitation::query()->count())->toBe(0)
        ->and(ReviewerReminder::query()->count())->toBe(0)
        ->and(OrganizationInvitation::query()->count())->toBe(0)
        ->and(EmailTemplate::query()->count())->toBe(0)
        ->and(EmailLog::query()->count())->toBe(0)
        ->and(ShortLink::query()->count())->toBe(0)
        ->and(ShortLinkVisit::query()->count())->toBe(0)
        ->and(DB::table('organization_members')->count())->toBe(0);

    foreach ($paths as $path) {
        Storage::disk('local')->assertMissing((string) $path);
    }
});

it('keeps the owner and any demo reviewer who belongs somewhere else', function () {
    $elsewhere = Conference::factory()->create();
    $kept = User::query()->where('email', 'demo.reviewer3@example.com')->firstOrFail();

    ConferenceReviewer::factory()->for($elsewhere)->create(['user_id' => $kept->getKey()]);

    $this->artisan('cass:demo-reset', ['--confirm' => true])->assertSuccessful();

    expect(User::query()->where('email', 'owner@example.org')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'demo.reviewer3@example.com')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'demo.reviewer1@example.com')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'demo.reviewer2@example.com')->exists())->toBeFalse()
        // The other organization is untouched.
        ->and(Conference::query()->whereKey($elsewhere->getKey())->exists())->toBeTrue()
        ->and(ConferenceReviewer::query()->where('conference_id', $elsewhere->getKey())->count())->toBe(1);
});

it('refuses without --confirm and changes nothing', function () {
    $this->artisan('cass:demo-reset')
        ->expectsOutputToContain('--confirm')
        ->assertFailed();

    expect(Organization::query()->where('slug', 'demo-society')->exists())->toBeTrue()
        ->and(Submission::query()->count())->toBe(15)
        ->and(SubmissionFile::query()->count())->toBe(15);
});

it('refuses an organization that is not flagged as demo data', function () {
    $this->organization->forceFill(['is_demo' => false])->save();

    $this->artisan('cass:demo-reset', ['--confirm' => true])
        ->expectsOutputToContain('is_demo')
        ->assertFailed();

    expect(Organization::query()->where('slug', 'demo-society')->exists())->toBeTrue()
        ->and(Conference::query()->count())->toBe(1)
        ->and(Submission::query()->count())->toBe(15);
});

it('says there is nothing to reset when the demo organization is already gone', function () {
    $this->artisan('cass:demo-reset', ['--confirm' => true])->assertSuccessful();

    $this->artisan('cass:demo-reset', ['--confirm' => true])
        ->expectsOutputToContain('demo-society')
        ->assertSuccessful();
});
