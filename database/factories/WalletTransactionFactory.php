<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $before = fake()->randomFloat(2, 500, 5000);
        $amount = fake()->randomFloat(2, 10, 500);
        $type = fake()->randomElement(['deposit', 'withdraw', 'refund']);

        $after = match ($type) {
            'deposit', 'refund' => $before + $amount,
            'withdraw' => $before - $amount,
        };

        return [
            'num' => (string) Str::ulid(),
            'wallet_id' => Wallet::factory(),
            'order_id' => Order::factory(),
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
        ];
    }
}
