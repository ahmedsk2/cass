<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Actions\Mail\SendTemplatedEmail;
use App\Actions\Reviewers\InviteReviewer;
use App\Enums\ConferenceStatus;
use App\Enums\ReminderThreshold;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewStatus;
use App\Exceptions\ReviewNotAcceptable;
use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewerReminder;
use App\Models\User;
use App\Support\Reviews\ReminderSchedule;
use App\Support\Reviews\ReviewerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Spec 5.4 step 5, for one conference at a time. The command in
 * app/Console/Commands loops conferences and does nothing else.
 */
class SendReviewerReminders
{
    public function __construct(private readonly SendTemplatedEmail $sendTemplatedEmail) {}

    /**
     * Active reviewers of this conference with at least one abstract in their
     * queue that they have not submitted a review for.
     *
     * One `exists` query per reviewer rather than one clever join: the queue
     * definition is ReviewerScope's and nothing else may restate it, and a
     * conference with fifty reviewers costs fifty cheap queries once an hour.
     *
     * @return Collection<int, ConferenceReviewer>
     */
    public function outstanding(Conference $conference): Collection
    {
        return $this->behind($conference, $conference->reviewers()
            ->where('status', ReviewerStatus::Active->value)
            ->with('user')
            ->orderBy('id')
            ->get());
    }

    /**
     * The filter half of outstanding(), over a set of reviewers somebody else
     * chose. Split out so automatic() can narrow the candidates in SQL first and
     * still ask exactly this question of the survivors - one definition of
     * "behind", not two.
     *
     * @param  Collection<int, ConferenceReviewer>  $reviewers
     * @return Collection<int, ConferenceReviewer>
     */
    private function behind(Conference $conference, Collection $reviewers): Collection
    {
        /** @var Collection<int, ConferenceReviewer> $result */
        $result = $reviewers->filter(function (ConferenceReviewer $reviewer) use ($conference): bool {
            $user = $reviewer->user;

            if (! $user instanceof User) {
                return false;
            }

            return ReviewerScope::submissions($user, $conference)
                ->whereDoesntHave('reviews', fn (Builder $reviews): Builder => $reviews
                    ->where('reviewer_user_id', $user->getKey())
                    ->where('status', ReviewStatus::Submitted->value))
                ->exists();
        })->values();

        return $result;
    }

    /**
     * The hourly pass for one conference.
     *
     * @return array{sent: int, threshold: ReminderThreshold|null}
     */
    public function automatic(Conference $conference): array
    {
        if ($conference->status !== ConferenceStatus::Reviewing) {
            return ['sent' => 0, 'threshold' => null];
        }

        if (! ReminderSchedule::isSendHour($conference)) {
            return ['sent' => 0, 'threshold' => null];
        }

        $threshold = ReminderSchedule::due($conference);

        if ($threshold === null) {
            return ['sent' => 0, 'threshold' => null];
        }

        // Ask the database who could still receive THIS threshold before
        // building any queue. `Overdue` stays due for ever once the deadline
        // passes (ReminderSchedule::due) and isSendHour() is true for most of
        // the day, so a conference left in `reviewing` would otherwise rebuild
        // its whole queue - one exists() per active reviewer, through
        // ReviewerScope - every hour, for ever, and send nothing. One
        // `whereNotExists` costs one query and usually answers "nobody".
        $candidates = $conference->reviewers()
            ->where('status', ReviewerStatus::Active->value)
            ->whereNotExists(fn (QueryBuilder $already): QueryBuilder => $already
                ->selectRaw('1')
                ->from('reviewer_reminders')
                ->whereColumn('reviewer_reminders.user_id', 'conference_reviewers.user_id')
                ->where('reviewer_reminders.conference_id', $conference->getKey())
                ->where('reviewer_reminders.threshold', $threshold->value))
            ->with('user')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return ['sent' => 0, 'threshold' => $threshold];
        }

        $sent = 0;

        foreach ($this->behind($conference, $candidates) as $reviewer) {
            $user = $reviewer->user;

            if (! $user instanceof User) {
                continue;
            }

            try {
                DB::transaction(function () use ($conference, $user, $threshold): void {
                    // The INSERT is the decision, not a read before it. Two
                    // overlapping runs - a manual `artisan cass:reviewer-reminders`
                    // beside the scheduled one, or a withoutOverlapping mutex
                    // that expired - both pass a read-then-write, and the second
                    // insert would raise an uncaught QueryException that
                    // abandons the whole pass mid-loop, silently skipping every
                    // later conference. The unique key on
                    // (conference_id, user_id, threshold) is what makes "already
                    // sent" a fact, so let it answer.
                    $reminder = new ReviewerReminder;
                    $reminder->forceFill([
                        'conference_id' => $conference->getKey(),
                        'user_id' => $user->getKey(),
                        'threshold' => $threshold,
                        'sent_at' => now(),
                    ])->save();

                    $this->send($conference, $user, $threshold);
                });
            } catch (UniqueConstraintViolationException) {
                // Another run got there first. Nothing to send, nothing wrong.
                continue;
            }

            $sent++;
        }

        return ['sent' => $sent, 'threshold' => $threshold];
    }

    /** @return list<string> empty when the organizer may send one now */
    public function manualBlockers(Conference $conference): array
    {
        $reasons = [];

        if ($conference->status !== ConferenceStatus::Reviewing) {
            $reasons[] = __('reviewer.remind.errors.not_reviewing');
        }

        $last = $conference->reviewer_reminded_at;
        $hours = (int) config('cass.reminders.manual_throttle_hours');

        if ($last !== null && $last->copy()->addHours($hours)->isFuture()) {
            $reasons[] = __('reviewer.remind.errors.too_soon', ['hours' => $hours]);
        }

        // The queue scan below is one query per active reviewer, and this method
        // runs inside remindReviewers()'s visible() - which means on EVERY
        // render of ViewConference and EditConference, both of which spread
        // ConferenceStatusActions::all() into their header actions. A conference
        // in review with a hundred reviewers would cost a hundred extra queries
        // per page view. The two reasons above are constant time and already
        // hide the button, so only ask the expensive question when neither
        // applies.
        if ($reasons !== []) {
            return $reasons;
        }

        if ($this->outstanding($conference)->isEmpty()) {
            $reasons[] = __('reviewer.remind.errors.nobody_behind');
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Spec 5.4 step 5's "Organizers can trigger a manual reminder". Writes no
     * reviewer_reminders row: this one is allowed to repeat, and that table's
     * unique key exists to stop repeats. The throttle is on the conference.
     */
    public function manual(Conference $conference, User $actor): int
    {
        $reasons = $this->manualBlockers($conference);

        if ($reasons !== []) {
            throw new ReviewNotAcceptable($reasons);
        }

        $hours = (int) config('cass.reminders.manual_throttle_hours');

        // Claim the window BEFORE sending, not after. manualBlockers() is a
        // read, so two overlapping clicks both pass it and both mail every
        // outstanding reviewer - the twice-a-day cap the button promises would
        // be advisory, and the amplification is one email per outstanding
        // reviewer per extra click. One conditional UPDATE, one winner: the same
        // shape as ReviewForm::lockIfUnlocked().
        $claimed = Conference::query()
            ->whereKey($conference->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('reviewer_reminded_at')
                ->orWhere('reviewer_reminded_at', '<=', now()->subHours($hours)))
            ->update(['reviewer_reminded_at' => now(), 'updated_at' => now()]) === 1;

        if (! $claimed) {
            throw new ReviewNotAcceptable([__('reviewer.remind.errors.too_soon', ['hours' => $hours])]);
        }

        // Keep the in-memory model in step with the row this just wrote, without
        // writing it a second time.
        $conference->forceFill(['reviewer_reminded_at' => now()])->syncOriginal();

        // Past the deadline the overdue wording is the honest one.
        $threshold = ReminderSchedule::due($conference) === ReminderThreshold::Overdue
            ? ReminderThreshold::Overdue
            : ReminderThreshold::Days7;

        $sent = 0;

        foreach ($this->outstanding($conference) as $reviewer) {
            $user = $reviewer->user;

            if (! $user instanceof User) {
                continue;
            }

            $this->send($conference, $user, $threshold);
            $sent++;
        }

        activity()
            ->performedOn($conference)
            ->causedBy($actor)
            ->withProperties(['reviewers' => $sent])
            ->log('reviewer.reminded');

        return $sent;
    }

    private function send(Conference $conference, User $user, ReminderThreshold $threshold): void
    {
        $this->sendTemplatedEmail->handle(
            $threshold->templateKey(),
            $conference,
            (string) $user->email,
            // The same bag Task 4's invitation uses, so a template that starts
            // using {{organization}} needs no second call site updated. Here
            // {{review_link}} is the reviewer's own queue, not an accept link.
            InviteReviewer::placeholderValues(
                $conference,
                (string) ($user->name ?? $user->email),
                SubmissionResource::urlForConference($conference),
            ),
        );
    }
}
