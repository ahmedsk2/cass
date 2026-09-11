<?php

declare(strict_types=1);

use App\Actions\Reviews\SendReviewerReminders;
use App\Enums\ConferenceStatus;
use App\Enums\EmailTemplateKey;
use App\Enums\OrganizationRole;
use App\Enums\ReminderThreshold;
use App\Enums\ReviewerStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Mail\TemplatedMail;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewerReminder;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Mail::fake();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'timezone' => 'Asia/Riyadh',
        'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ]);

    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();

    $this->behind = User::factory()->create(['name' => 'Dr Omar Khan', 'email' => 'omar@example.org']);
    $this->done = User::factory()->create(['name' => 'Dr Sara Nasser', 'email' => 'sara@example.org']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->behind->id]);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->done->id]);

    Review::factory()->for($this->submission)->submitted()->create(['reviewer_user_id' => $this->done->id]);

    // 04:00 UTC on 27 October is 07:00 Riyadh, seven days out.
    Carbon::setTestNow(Carbon::parse('2026-10-27 04:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('names only the reviewers with outstanding work', function () {
    expect(app(SendReviewerReminders::class)->outstanding($this->conference)->pluck('user_id')->all())
        ->toBe([$this->behind->id]);
});

it('never reminds a removed reviewer or a reviewer of another conference', function () {
    // outstanding() is the ONLY gate on who gets emailed, and every other case
    // in this file feeds it two active reviewers of one conference - so without
    // this, dropping the `status = active` filter or the per-conference
    // constraint would email removed reviewers and strangers with a green suite.
    $removed = User::factory()->create(['email' => 'removed@example.org']);
    ConferenceReviewer::factory()->for($this->conference)->removed()->create(['user_id' => $removed->id]);

    $elsewhere = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'timezone' => 'Asia/Riyadh',
        'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ]);
    $theirReviewer = User::factory()->create(['email' => 'elsewhere@example.org']);
    ConferenceReviewer::factory()->for($elsewhere)->create(['user_id' => $theirReviewer->id]);
    Submission::factory()->for($elsewhere)->submitted()->create();

    expect(app(SendReviewerReminders::class)->outstanding($this->conference)->pluck('user_id')->all())
        ->toBe([$this->behind->id]);

    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertNotQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('removed@example.org'),
    );

    // The other conference gets its own reminder, to its own reviewer, carrying
    // its own organization - never this one's.
    Mail::assertNotQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('elsewhere@example.org')
            && $mail->organization->is($this->organization),
    );
});

it('emails the seven-day reminder once and records that it did', function () {
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('omar@example.org')
            && $mail->templateKey === EmailTemplateKey::ReviewerReminder->value
            // {{review_link}} in a reminder is the reviewer's queue, not an
            // accept link (spec 5.4 step 5).
            && str_contains($mail->body, '/review'),
    );
    Mail::assertQueued(TemplatedMail::class, 1);

    expect(ReviewerReminder::query()->count())->toBe(1)
        ->and(ReviewerReminder::query()->first()?->threshold)->toBe(ReminderThreshold::Days7)
        ->and(EmailLog::query()->where('template_key', 'reviewer_reminder')->count())->toBe(1);

    // The hourly run is idempotent: the unique key is the memory.
    artisan('cass:reviewer-reminders')->assertExitCode(0);
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertQueued(TemplatedMail::class, 1);
    expect(ReviewerReminder::query()->count())->toBe(1);
});

it('waits for the local send hour', function () {
    // 03:00 UTC is 06:00 Riyadh.
    Carbon::setTestNow(Carbon::parse('2026-10-27 03:00:00', 'UTC'));

    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertNothingQueued();
    expect(ReviewerReminder::query()->count())->toBe(0);
});

it('walks down the thresholds one email at a time', function () {
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Carbon::setTestNow(Carbon::parse('2026-10-31 04:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Carbon::setTestNow(Carbon::parse('2026-11-02 04:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Carbon::setTestNow(Carbon::parse('2026-11-04 04:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    // orderBy('id'): no ORDER BY means insertion order only by luck, and MySQL -
    // which Task 13 makes the real gate - is under no obligation to return a
    // scan in primary-key order.
    expect(ReviewerReminder::query()->orderBy('id')->pluck('threshold')->map(fn ($t) => $t->value)->all())
        ->toBe(['days_7', 'days_3', 'days_1', 'overdue']);

    Mail::assertQueued(TemplatedMail::class, 4);
    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->templateKey === EmailTemplateKey::ReviewerOverdue->value,
    );
});

it('sends the overdue email once and then stops', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-04 04:00:00', 'UTC'));

    artisan('cass:reviewer-reminders')->assertExitCode(0);
    Carbon::setTestNow(Carbon::parse('2026-11-05 04:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertQueued(TemplatedMail::class, 1);
    expect(ReviewerReminder::query()->count())->toBe(1);
});

it('stops reminding a reviewer who has finished', function () {
    Review::factory()->for($this->submission)->submitted()->create(['reviewer_user_id' => $this->behind->id]);

    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertNothingQueued();
});

it('ignores a conference that is not in review, or has no deadline', function () {
    $this->conference->forceFill(['status' => ConferenceStatus::Closed])->save();
    artisan('cass:reviewer-reminders')->assertExitCode(0);
    Mail::assertNothingQueued();

    $this->conference->forceFill(['status' => ConferenceStatus::Reviewing, 'review_deadline' => null])->save();
    artisan('cass:reviewer-reminders')->assertExitCode(0);
    Mail::assertNothingQueued();
});

it('skips a conference that throws and still reminds every later one', function () {
    // The pass is ordered by id and automatic() catches only a unique violation,
    // so before this any other throw for one conference abandoned the whole
    // hourly run - and every conference after it in id order got nothing, every
    // hour, with nothing visible but a red scheduled command.
    $second = Conference::factory()->for($this->organization)->create([
        'name' => 'Beta Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
        'timezone' => 'Asia/Riyadh',
        'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ]);
    Submission::factory()->for($second)->submitted()->create();
    $laterReviewer = User::factory()->create(['email' => 'later@example.org']);
    ConferenceReviewer::factory()->for($second)->create(['user_id' => $laterReviewer->id]);

    $real = app(SendReviewerReminders::class);
    $broken = $this->conference;

    $this->mock(SendReviewerReminders::class, function (MockInterface $mock) use ($real, $broken): void {
        $mock->shouldReceive('automatic')->andReturnUsing(
            function (Conference $conference) use ($real, $broken): array {
                if ($conference->is($broken)) {
                    throw new RuntimeException('anything at all');
                }

                return $real->automatic($conference);
            },
        );
    });

    // Visibly red, so a scheduled run that lost a conference is not silent...
    artisan('cass:reviewer-reminders')->assertExitCode(1);

    // ...and the healthy conference after it still got its reminder.
    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('later@example.org'));
    Mail::assertNotQueued(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('omar@example.org'));
});

it('sends a manual reminder from the conference page and throttles the next one', function () {
    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);
    actingAs($owner);
    bootOrganizerPanel($this->organization);

    livewire(ViewConference::class, ['record' => $this->conference->getRouteKey()])
        ->callAction('remindReviewers')
        ->assertNotified();

    // Seven days out, so the approaching-deadline wording is the honest one -
    // and the send is logged like every other templated email. A bare
    // `assertQueued(TemplatedMail::class, 1)` passed with the overdue template,
    // or with no email_logs row at all.
    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('omar@example.org')
            && $mail->templateKey === EmailTemplateKey::ReviewerReminder->value,
    );
    Mail::assertQueued(TemplatedMail::class, 1);

    expect(EmailLog::query()->where('template_key', EmailTemplateKey::ReviewerReminder->value)
        ->where('to_email', 'omar@example.org')->count())->toBe(1);

    expect($this->conference->fresh()?->reviewer_reminded_at)->not->toBeNull()
        // A manual send is allowed to repeat, so it writes no reviewer_reminders
        // row - that table's unique key exists to stop repeats.
        ->and(ReviewerReminder::query()->count())->toBe(0);

    livewire(ViewConference::class, ['record' => $this->conference->fresh()->getRouteKey()])
        ->assertActionHidden('remindReviewers');

    expect(app(SendReviewerReminders::class)->manualBlockers($this->conference->fresh()))->not->toBe([]);

    Carbon::setTestNow(now()->addHours((int) config('cass.reminders.manual_throttle_hours') + 1));

    expect(app(SendReviewerReminders::class)->manualBlockers($this->conference->fresh()))->toBe([]);
});

it('offers no manual reminder when nobody is behind', function () {
    Review::factory()->for($this->submission)->submitted()->create(['reviewer_user_id' => $this->behind->id]);

    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);
    actingAs($owner);
    bootOrganizerPanel($this->organization);

    expect(app(SendReviewerReminders::class)->manualBlockers($this->conference))->not->toBe([]);

    livewire(ViewConference::class, ['record' => $this->conference->getRouteKey()])
        ->assertActionHidden('remindReviewers');
});

it('skips rather than crashes when the reminder row already exists', function () {
    // Idempotency is the INSERT, not a read before it. A manual
    // `artisan cass:reviewer-reminders` running beside the scheduled one - or a
    // withoutOverlapping mutex that expired - has both runs pass a
    // read-then-write, and the second insert would raise an uncaught
    // QueryException that abandons the rest of the hourly pass. Every later
    // conference in that run would be silently skipped, which is the opposite
    // of what the unique key is for.
    ReviewerReminder::factory()->create([
        'conference_id' => $this->conference->id,
        'user_id' => $this->behind->id,
        'threshold' => ReminderThreshold::Days7,
    ]);

    artisan('cass:reviewer-reminders')->assertExitCode(0);

    Mail::assertNothingQueued();
    expect(ReviewerReminder::query()->count())->toBe(1);
});

it('does no per-reviewer work once every reviewer has the current threshold', function () {
    // `Overdue` stays due for ever once the deadline passes, and isSendHour() is
    // true for most of the day - so a conference left in `reviewing` would
    // otherwise rebuild its whole queue, one ReviewerScope exists() per active
    // reviewer, every hour for ever, and send nothing. One `whereNotExists`
    // decides who could still receive this threshold before any of that runs.
    //
    // The caught-up reviewer is removed first so that every reviewer who is left
    // ends the first run holding a row: that is the state the short-circuit is
    // for, and it is the state a conference settles into.
    ConferenceReviewer::query()->where('user_id', $this->done->id)->firstOrFail()
        ->forceFill(['status' => ReviewerStatus::Removed, 'removed_at' => now()])->save();

    Carbon::setTestNow(Carbon::parse('2026-11-04 04:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);

    expect(ReviewerReminder::query()->where('threshold', ReminderThreshold::Overdue->value)->count())->toBe(1);

    DB::enableQueryLog();
    Carbon::setTestNow(Carbon::parse('2026-11-04 05:00:00', 'UTC'));
    artisan('cass:reviewer-reminders')->assertExitCode(0);
    $second = DB::getQueryLog();
    DB::disableQueryLog();

    // The per-reviewer scan is the only thing in this command that selects from
    // `submissions` (through ReviewerScope); the candidate query does not.
    expect(collect($second)->filter(fn (array $query): bool => str_contains((string) $query['query'], 'from "submissions"')
        || str_contains((string) $query['query'], 'from `submissions`')))
        ->toBeEmpty();

    Mail::assertQueued(TemplatedMail::class, 1);
});

it('claims the manual window before sending, so two clicks send one batch', function () {
    // Read-then-send lets two overlapping requests both pass manualBlockers()
    // and both mail everybody. manual() claims the window with one conditional
    // UPDATE first - the same shape as the review-form lock - so the loser
    // refuses instead of amplifying by one email per outstanding reviewer.
    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);

    app(SendReviewerReminders::class)->manual($this->conference->fresh(), $owner);

    expect(fn () => app(SendReviewerReminders::class)->manual($this->conference->fresh(), $owner))
        ->toThrow(ReviewNotAcceptable::class);

    Mail::assertQueued(TemplatedMail::class, 1);
});

it('sends the overdue wording when the manual reminder goes out after the deadline', function () {
    // The other branch of manual()'s threshold choice, which every other case in
    // this file misses because they all run seven days out. "Your deadline is
    // approaching" sent a day after the deadline is the wrong sentence.
    Carbon::setTestNow(Carbon::parse('2026-11-04 04:00:00', 'UTC'));

    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);

    expect(app(SendReviewerReminders::class)->manual($this->conference->fresh(), $owner))->toBe(1);

    Mail::assertQueued(
        TemplatedMail::class,
        fn (TemplatedMail $mail): bool => $mail->hasTo('omar@example.org')
            && $mail->templateKey === EmailTemplateKey::ReviewerOverdue->value,
    );

    expect(EmailLog::query()->where('template_key', EmailTemplateKey::ReviewerOverdue->value)->count())->toBe(1);
});

it('is registered on the schedule and as an artisan command', function () {
    expect(collect(Artisan::all()))->toHaveKey('cass:reviewer-reminders');

    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($events->filter(fn (string $command): bool => str_contains($command, 'cass:reviewer-reminders')))
        ->not->toBeEmpty();
});
