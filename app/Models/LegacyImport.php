<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LegacyImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row of the legacy dump, and the v2 model it became.
 *
 * Spec 5.10 asks for an import that is "idempotent by legacy id". This table is
 * that guarantee, rather than a nullable `legacy_id` column on eight production
 * tables: one artefact in one place, one unique index that makes every step of
 * the import idempotent by construction, and a single migration to drop the day
 * the owner is confident the import will never run again.
 */
class LegacyImport extends Model
{
    /** @use HasFactory<LegacyImportFactory> */
    use HasFactory;

    /**
     * Everything here is written by record(), which forceFills. The table is an
     * internal artefact of one console command and nothing should be able to
     * repoint a mapping with a `LegacyImport::create($request->all())`.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * The migration has one timestamp of its own and no created_at/updated_at:
     * a mapping row is written once and never edited, so the pair would be two
     * columns that always agree with imported_at.
     */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
            'imported_id' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function imported(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The model a legacy row was imported as, or null. The type is checked by
     * the caller: a mapping row whose target has since been purged returns
     * null from the morph, and an import that then re-creates it is the
     * correct behaviour.
     */
    public static function find(string $table, int $legacyId): ?Model
    {
        return static::query()
            ->where('legacy_table', $table)
            ->where('legacy_id', $legacyId)
            ->first()?->imported;
    }

    /**
     * Record one mapping, and RETURN the mapping row itself. Idempotent: the
     * unique index is the guarantee, this is the convenience. The return value
     * is what lets a caller assert on the mapping row's own key.
     *
     * Written with forceFill rather than updateOrCreate(): every column is
     * guarded, and updateOrCreate() fills the found-or-new instance, which on a
     * totally guarded model is a MassAssignmentException rather than a write.
     */
    public static function record(string $table, int $legacyId, Model $model): self
    {
        $mapping = static::query()
            ->where('legacy_table', $table)
            ->where('legacy_id', $legacyId)
            ->first() ?? new self;

        $mapping->forceFill([
            'legacy_table' => $table,
            'legacy_id' => $legacyId,
            'imported_type' => $model->getMorphClass(),
            'imported_id' => $model->getKey(),
            'imported_at' => now(),
        ])->save();

        return $mapping;
    }
}
