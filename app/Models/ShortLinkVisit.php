<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShortLinkVisit extends Model
{
    use MassPrunable;

    /** There is no created_at/updated_at pair: `visited_at` is the whole row. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['visited_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['visited_at' => 'datetime'];
    }

    /** @return BelongsTo<ShortLink, $this> */
    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }

    /**
     * `/q/{code}` is open to the internet and writes a row per counted scan,
     * so the table has no natural ceiling. The sharing page only ever reports
     * the last 30 days, so rows past the retention window are never read
     * again: `model:prune` deletes them nightly (routes/console.php).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('cass.short_link_visit_retention_days'));

        return static::query()->where('visited_at', '<', CarbonImmutable::now()->subDays($days));
    }
}
