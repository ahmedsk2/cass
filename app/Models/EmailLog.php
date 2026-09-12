<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailLogStatus;
use Carbon\CarbonImmutable;
use Database\Factories\EmailLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailLog extends Model
{
    /** @use HasFactory<EmailLogFactory> */
    use HasFactory;

    use MassPrunable;

    /** The width of the `subject` column in 2026_09_11_001500_create_email_logs_table. */
    public const SUBJECT_MAX_LENGTH = 255;

    /** Written only by SendTemplatedEmail and RecordOutgoingEmail. */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EmailLogStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EmailLog $log): void {
            $log->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Spec section 8 has no retention rule for this table and three plans have
     * recorded that it grows by one row per email for ever. A year, rather
     * than short_link_visits' ninety days: an email log answers "did this
     * author ever receive their decision letter", and that question arrives
     * months after a conference, while a scan count is a number nobody asks
     * about twice.
     *
     * MassPrunable rather than Prunable: this fires no model events, and
     * EmailLog has a `creating` hook and no deleting hook, so there is nothing
     * to fire. routes/console.php already runs model:prune daily.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('cass.email_log_retention_days'));

        return static::query()->where('created_at', '<', CarbonImmutable::now()->subDays($days));
    }

    /**
     * `subject` is varchar(255) and MySQL runs in strict mode
     * (config/database.php), so an over-long value is an exception on the insert
     * rather than a clipped column - and that exception would abort the send the
     * row exists only to record, inside a listener or an action that has already
     * decided to send. Both writers build the subject out of text somebody else
     * typed: SendTemplatedEmail from a template an organizer may fill with
     * `{{title}}` (submissions.title is varchar(255) in its own right), and
     * RecordOutgoingEmail from a notification subject built out of
     * conferences.name. The trim therefore lives on the model, where neither of
     * them - nor Plan 4's and Plan 5's - can forget it.
     *
     * mb_substr and not Str::limit(): Str::limit measures mb_strwidth, and a
     * title made of zero-width combining marks has width 0 and a character count
     * MySQL still rejects. The column counts characters, so this does too.
     *
     * SendTemplatedEmail redacts an author's status token before this runs, so
     * trimming can never leave half a bearer credential in the log.
     *
     * @return Attribute<never, string>
     */
    protected function subject(): Attribute
    {
        return Attribute::set(
            fn (string $value): string => mb_substr($value, 0, self::SUBJECT_MAX_LENGTH),
        );
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
