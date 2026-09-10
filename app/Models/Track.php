<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Track extends Model
{
    /** @use HasFactory<TrackFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'sort'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Track $track): void {
            $track->ulid ??= (string) Str::ulid();
            // Filament's CreateAction only fills the form data and saves, so
            // nothing sets the reorder column and a new row would take the DB
            // default 0 and jump above every existing track. Append instead;
            // an explicit sort (the factories, the review-form template) wins.
            $track->sort ??= ((int) static::query()->where('conference_id', $track->conference_id)->max('sort')) + 1;
        });
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }
}
