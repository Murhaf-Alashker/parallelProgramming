<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderingRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function order(OrderingRequest $request):JsonResponse
    {
        $data = $request->validated();
        $user = User::where('email',$request->email)->firstOrFail();
        $requestedProducts = $this->bringOrderedProducts($data);
        $requestedProductsIds = $requestedProducts->keys()->sort()->values()->toArray();

        try{
            return DB::transaction(function () use ($requestedProducts,$user,$requestedProductsIds) {
                $wallet = Wallet::where('user_id', $user->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $products = Product::whereIn('id', $requestedProductsIds)->lockForUpdate()->get();
                return $this->toDatabase($user, $wallet, $requestedProducts, $products);
            });
        }
        catch (\Exception $exception)
        {
            return response()->json(['message' => $exception->getMessage()],400);
        }

    }


    public function bringOrderedProducts(array $data):Collection
    {
        // إذا نفس المنتج تكرر بالطلب، منجمع الكميات تبعو
         return collect($data['products'])
            ->groupBy('id')
            ->map(function ($items) {
                return $items->sum('quantity');
            });

    }

    public function toDatabase(User $user,Wallet $wallet,Collection $requestedProducts,Collection $products):JsonResponse
    {
        $totalPrice = 0;
        // فحص الكميات وحساب السعر النهائي
        foreach ($products as $product) {
            $quantity = $requestedProducts[$product->id];

            if ($product->quantity < $quantity) {
                throw new \Exception('there is no enough stock');
            }

            $totalPrice += $product->price * $quantity;
        }

        // فحص رصيد المحفظة
        if ($wallet->balance < $totalPrice) {
            throw new \Exception('you dont have enough balance');
        }

        // إنشاء الطلب
        $order = $user->orders()->create([
            'num' => Str::ulid(),
            'total_price' => $totalPrice,
            'status' => 'paid',
        ]);

        // خصم الكميات وإنشاء عناصر الطلب
        foreach ($products as $product) {
            $quantity = $requestedProducts[$product->id];

            $product->decrement('quantity', $quantity);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $product->price,
                'total_price' => $product->price * $quantity,
            ]);
        }

        // خصم من المحفظة
        $balanceBefore = $wallet->balance;

        $wallet->decrement('balance', $totalPrice);

        $wallet->transactions()->create([
            'num' => Str::ulid(),
            'order_id' => $order->id,
            'type' => 'withdraw',
            'amount' => $totalPrice,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceBefore - $totalPrice,
        ]);

        return response()->json([
            'message' => 'payment success',
            'order_id' => $order->id,
            'total_price' => $totalPrice,
        ]);
    }
}
