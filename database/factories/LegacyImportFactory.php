<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conference;
use App\Models\LegacyImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LegacyImport> */
class LegacyImportFactory extends Factory
{
    protected $model = LegacyImport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'legacy_table' => 'conferences',
            'legacy_id' => fake()->unique()->numberBetween(1, 100000),
            'imported_type' => (new Conference)->getMorphClass(),
            'imported_id' => Conference::factory(),
            'imported_at' => now(),
        ];
    }
}
