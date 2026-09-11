<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReminderThreshold;
use Database\Factories\ReviewerReminderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (conference, reviewer, threshold), written the moment the email
 * is queued. The unique key on those three columns is the whole idempotency
 * story: the hourly command does not have to remember anything, and a second
 * run in the same hour - or a worker that restarts mid-send - cannot send
 * twice.
 */
class ReviewerReminder extends Model
{
    /** @use HasFactory<ReviewerReminderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'threshold' => ReminderThreshold::class,
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
