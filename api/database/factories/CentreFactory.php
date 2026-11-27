<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Centre;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Centre>
 */
class CentreFactory extends Factory
{
    protected $model = Centre::class;

    public function definition(): array
    {
        return [
            'name' => fake()->city().' centre',
            'code' => strtoupper(fake()->unique()->bothify('C-###')),
            'latitude' => -26.2485,
            'longitude' => 27.8540,
        ];
    }
}
