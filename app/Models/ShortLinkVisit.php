<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShortLinkVisit extends Model
{
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
}
