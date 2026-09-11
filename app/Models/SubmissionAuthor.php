<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SubmissionAuthorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionAuthor extends Model
{
    /** @use HasFactory<SubmissionAuthorFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'affiliation', 'is_presenter', 'is_corresponding', 'sort'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_presenter' => 'boolean',
            'is_corresponding' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
