<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function store(User $user,float $totalPrice):Order
    {
        return $user->orders()->create([
            'num' => (string) Str::ulid(),
            'total_price' => $totalPrice,
            'status' => 'paid',
        ]);
    }
}
