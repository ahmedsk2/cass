<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomFieldType;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CustomField extends Model
{
    /** @use HasFactory<CustomFieldFactory> */
    use HasFactory;

    /**
     * `key` is derived from the label on creation and never re-derived: it is
     * the storage key for every answer already submitted (Plan 3).
     *
     * @var list<string>
     */
    protected $fillable = [
        'label',
        'help_text',
        'type',
        'options',
        'required',
        // Spec 5.4 step 4. A blind reviewer must not read the author's
        // institution out of a question the organizer wrote; Task 6's
        // ReviewSubmission::getCustomFieldLines() drops every field with this
        // set when Conference::hidesAuthorsFrom() says the reader is blinded.
        'hide_from_reviewers',
        'sort',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'options' => 'array',
            'required' => 'boolean',
            'hide_from_reviewers' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CustomField $field): void {
            $field->ulid ??= (string) Str::ulid();
            $field->key ??= static::uniqueKey((int) $field->conference_id, (string) $field->label);
            // Append rather than take the DB default 0; see Track::booted().
            $field->sort ??= ((int) static::query()->where('conference_id', $field->conference_id)->max('sort')) + 1;
        });
    }

    public static function uniqueKey(int $conferenceId, string $label): string
    {
        $base = Str::of($label)->slug('_')->limit(56, '')->toString() ?: 'field';
        $key = $base;
        $n = 2;

        while (static::query()->where('conference_id', $conferenceId)->where('key', $key)->exists()) {
            $key = "{$base}_{$n}";
            $n++;
        }

        return $key;
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }
}
