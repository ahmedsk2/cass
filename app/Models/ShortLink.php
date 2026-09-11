<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ShortCode;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\ShortLinkFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

class ShortLink extends Model
{
    /** @use HasFactory<ShortLinkFactory> */
    use HasFactory;

    /**
     * Codes, counters and the target are never mass assigned: a printed code
     * must not be repointable, and the scan count must not be settable, by a
     * future `ShortLink::create($validated)`. Everything here is written by
     * property assignment in forTarget() or by increment().
     */
    protected $guarded = ['*'];

    /**
     * Test seam for the uniqueness retry in forTarget(). `self::` and not
     * `static::`: Larastan reports "unsafe access to private property through
     * static::" on a non-final class.
     *
     * @var (Closure(): string)|null
     */
    private static ?Closure $codeGenerator = null;

    /** Replaces ShortCode::generate() until it is reset with null. */
    public static function generateCodesUsing(?Closure $generator): void
    {
        self::$codeGenerator = $generator;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['clicks' => 'integer'];
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<ShortLinkVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(ShortLinkVisit::class);
    }

    /**
     * Idempotent: publishing a conference twice reuses the printed code so a
     * poster already in circulation keeps working.
     *
     * The lookup below is a select-then-insert, so idempotence is really the
     * unique index on (target_type, target_id): when two publishes race, the
     * loser's insert violates it and this loop re-reads the winner's row
     * rather than printing a second code for the same conference.
     */
    public static function forTarget(Model $target): self
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $existing = static::query()
                ->where('target_type', $target->getMorphClass())
                ->where('target_id', $target->getKey())
                ->first();

            if ($existing instanceof self) {
                return $existing;
            }

            try {
                return self::insertForTarget($target);
            } catch (UniqueConstraintViolationException) {
                // Either another request won the race for this target, or two
                // requests drew the same code. The next pass returns the
                // winner's row, or draws a fresh code.
            }
        }

        throw new RuntimeException('Could not allocate a short link for '.$target->getMorphClass().' after 5 attempts.');
    }

    private static function insertForTarget(Model $target): self
    {
        do {
            $code = self::$codeGenerator !== null ? (self::$codeGenerator)() : ShortCode::generate();
        } while (static::query()->where('code', $code)->exists());

        $link = new self;
        $link->code = $code;
        $link->target()->associate($target);
        $link->save();

        return $link;
    }

    public function url(): string
    {
        return route('shortlink.show', ['code' => $this->code]);
    }

    /**
     * Zero-filled daily counts, newest day last, keyed by Y-m-d. Grouping in
     * PHP rather than SQL keeps this identical on SQLite and MySQL, and the
     * rows are streamed in batches because the row count is decided by
     * whoever scans /q, not by the organizer: the endpoint is open, writes a
     * row per counted scan, and its only ceilings are per minute. Loading the
     * window in one get() would hand that ceiling to the sharing page's
     * memory limit.
     *
     * Timestamps are stored UTC (spec section 10), so the caller passes the
     * timezone the days should be cut in - the sharing page passes the
     * conference's own, otherwise a scan at 01:00 in Riyadh would be counted
     * on the previous day next to a deadline printed in local time.
     *
     * @return array<string, int>
     */
    public function dailyVisitCounts(int $days = 30, string $timezone = 'UTC'): array
    {
        $start = CarbonImmutable::now($timezone)->subDays($days - 1)->startOfDay();

        $counts = [];
        for ($i = 0; $i < $days; $i++) {
            $counts[$start->addDays($i)->toDateString()] = 0;
        }

        $this->visits()
            // The query builder formats a Carbon binding without converting it,
            // so the local start of day has to be sent as UTC explicitly.
            ->where('visited_at', '>=', $start->utc())
            // `id` is not used here but chunkById pages on it, so it has to be
            // selected; `visited_at` is the only other column read.
            ->select(['id', 'visited_at'])
            ->chunkById(1000, function (Collection $visits) use (&$counts, $timezone): void {
                foreach ($visits as $visit) {
                    $day = $visit->visited_at->setTimezone($timezone)->toDateString();

                    if (array_key_exists($day, $counts)) {
                        $counts[$day]++;
                    }
                }
            });

        return $counts;
    }
}
