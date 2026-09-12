<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Decision;
use Database\Factories\SubmissionDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One row per decision *event* (spec 5.6: "Each decision records who and when
 * and appends to `SubmissionDecision`"), plus the letter that announced it.
 *
 * The letter columns are filled when this decision's email is queued and are
 * null until then. A resend of the SAME decision overwrites them, because the
 * author is now holding a newly sent email and /s/{token} has to show what is
 * in their inbox; a CHANGED decision appends a new row and leaves this one's
 * letter alone, which is what keeps the audit honest. Every individual send is
 * recorded in `email_logs` regardless.
 */
class SubmissionDecision extends Model
{
    /** @use HasFactory<SubmissionDecisionFactory> */
    use HasFactory;

    /**
     * Every column is written by ApplyDecision or by SendOneDecisionEmail with
     * forceFill(). Model::preventSilentlyDiscardingAttributes() is on outside
     * production, so a fillable `decision` would be a form field that decides.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decision' => Decision::class,
            'decided_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SubmissionDecision $decision): void {
            $decision->ulid ??= (string) Str::ulid();
        });
    }

    /** Spec section 3: ULIDs are the public identifiers. */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function wasNotified(): bool
    {
        return $this->notified_at !== null && $this->letter_markdown !== null;
    }

    /**
     * Who made this decision, for the history list. A null `decided_by` is a
     * deleted account, not an anonymous decision - the row is nulled on delete
     * precisely so the decision survives - so it says so rather than printing
     * an empty cell.
     *
     * The null test is on the FOREIGN KEY and the relation is then walked with
     * `->` rather than `?->`: Larastan types a BelongsTo accessor as
     * non-nullable and rejects the nullsafe hop outright (nullsafe.neverNull),
     * the trap Conference::reviewProgress() and ConferenceReviewers::rows()
     * both record. `decided_by` is nullOnDelete, so a non-null key always has
     * its user row - the two branches are the same fact read twice.
     */
    public function actorName(): string
    {
        if ($this->decided_by === null) {
            return (string) __('decisions.history.former_member');
        }

        return (string) $this->decidedBy->name;
    }
}
