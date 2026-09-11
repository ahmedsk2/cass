<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailTemplateKey;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory;

    /**
     * `key` and `conference_id` identify the row and are set by
     * SaveEmailTemplate; only the two editable fields are fillable.
     *
     * @var list<string>
     */
    protected $fillable = ['subject', 'body'];

    /**
     * `key` is not cast to EmailTemplateKey. A row whose key no longer resolves
     * (a template retired by a later plan) must still load and still be
     * deletable; an enum cast would throw on hydration instead.
     */
    public function templateKey(): ?EmailTemplateKey
    {
        return EmailTemplateKey::tryFrom((string) $this->key);
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }
}
