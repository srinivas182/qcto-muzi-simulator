<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create users inside a tenant context: app(TenantContext::class)->runAs($tenant, fn () => User::factory()->create()).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2782'.fake()->unique()->numerify('#######'),
            'password' => 'correct-horse-battery',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ];
    }

    public function learner(): static
    {
        return $this->state(['email' => null, 'password' => null]);
    }
}
