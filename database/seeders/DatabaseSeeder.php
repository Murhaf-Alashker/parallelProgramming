<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {

            $admin = User::factory()->create([
                'name' => 'Admin',
                'email' => 'admin@test.com',
                'role' => 'admin',
                'password' => Hash::make('Password123'),
            ]);

            $users = User::factory()
                ->count(50)
                ->sequence(fn (Sequence $sequence) => [
                    'name' => 'Test User ' . ($sequence->index + 1),
                    'email' => 'testuser' . ($sequence->index + 1) . '@gmail.com',
                    'password' => Hash::make('Password123'),
                    'role' => 'user',
                ])
                ->create();

            // كل المستخدمين: admin + users
            $allUsers = collect([$admin])->merge($users);

            // إنشاء محفظة واحدة فقط لكل مستخدم
            foreach ($allUsers as $user) {
                Wallet::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'num' =>Str::ulid(),
                        'balance' => 18,
                    ]
                );
            }

            $categories = Category::factory(5)->create();

            foreach ($categories as $category) {
                Product::factory(10)->create([
                    'category_id' => $category->id,
                ]);
            }

//            $products = Product::all();

            foreach (range(0, 1000) as $a) {
                foreach (User::with('wallet')->get() as $user) {
                    $wallet = $user->wallet;

                    $order = Order::factory()->create([
                        'user_id' => $user->id,
                        'num' =>Str::ulid(),
                        'status' => 'paid',
                        'total_price' => fake()->randomElement(range(20,200)),
                    ]);

//                    $total = 0;
//
//                    $selectedProducts = $products->random(2);
//
//                    foreach ($selectedProducts as $product) {
//                        $quantity = rand(1, 3);
//                        $unitPrice = $product->price;
//                        $itemTotal = $quantity * $unitPrice;
//
//                        OrderItem::factory()->create([
//                            'order_id' => $order->id,
//                            'product_id' => $product->id,
//                            'quantity' => $quantity,
//                            'unit_price' => $unitPrice,
//                            'total_price' => $itemTotal,
//                        ]);
//
//                        $total += $itemTotal;
//                    }
//
//                    $order->update([
//                        'total_price' => $total,
//                    ]);
//
//                    Payment::factory()->create([
//                        'order_id' => $order->id,
//                        'user_id' => $user->id,
//                        'num' =>Str::ulid(),
//                        'amount' => $total,
//                        'method' => 'wallet',
//                        'status' => 'success',
//                    ]);
//
//                    WalletTransaction::factory()->create([
//                        'wallet_id' => $wallet->id,
//                        'order_id' => $order->id,
//                        'type' => 'withdraw',
//                        'amount' => $total,
//                        'balance_before' => $wallet->balance,
//                        'balance_after' => $wallet->balance - $total,
//                    ]);
//
//                    $wallet->update([
//                        'balance' => $wallet->balance - $total,
//                    ]);
                }
            }
        });
    }
}
