<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomFieldType;
use App\Models\Conference;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomField> */
class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conference_id' => Conference::factory(),
            'label' => fake()->unique()->words(3, true),
            'help_text' => null,
            'type' => CustomFieldType::Text,
            'options' => null,
            'required' => false,
            'sort' => 0,
        ];
    }

    public function select(string ...$options): static
    {
        return $this->state(fn () => [
            'type' => CustomFieldType::Select,
            'options' => $options === [] ? ['Yes', 'No'] : array_values($options),
        ]);
    }
}
