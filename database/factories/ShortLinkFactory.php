<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conference;
use App\Models\ShortLink;
use App\Support\ShortCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShortLink> */
class ShortLinkFactory extends Factory
{
    protected $model = ShortLink::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => ShortCode::generate(),
            'target_type' => (new Conference)->getMorphClass(),
            'target_id' => Conference::factory(),
            'clicks' => 0,
        ];
    }
}
