<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'num' => (string) Str::ulid(),
            'user_id' => User::factory(),
            'total_price' => 0,
            'status' => fake()->randomElement(['pending', 'paid', 'cancelled', 'failed']),
        ];
    }
}
