<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Support\Str;

class WalletService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function decrementAndCreateTransaction(Wallet $wallet, Order $order, float $totalPrice):void
    {
        // خصم من المحفظة
        $balanceBefore = $wallet->balance;

        $wallet->decrement('balance', $totalPrice);

        $this->createTransaction($wallet,$order,$totalPrice,$balanceBefore);
    }

    public function createTransaction(Wallet $wallet,Order $order, float $totalPrice,float $balanceBefore):void
    {
        $wallet->transactions()->create([
            'num' => (string) Str::ulid(),
            'order_id' => $order->id,
            'type' => 'withdraw',
            'amount' => $totalPrice,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceBefore - $totalPrice,
        ]);
    }
}
