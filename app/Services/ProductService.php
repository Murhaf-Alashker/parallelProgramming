<?php

namespace App\Services;

use App\Jobs\GenerateInvoiceJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;


class ProductService
{
    /**
     * Create a new class instance.
     */
    public function __construct(protected OrderService $orderService, protected WalletService $walletService)
    {
        //
    }
    public function order(User $user,array $data):JsonResponse
    {
        $requestedProducts = $this->bringOrderedProducts($data);
        // مثلا في حالة قدوم اكثر من طلب في وقت واحد وبفرض الاول فيه requestedProductsIds = [1,2]
        //والثاني فيه requestedProductsIds = [2,1]
        // وتم عمل lock على المنتج ذو المعرفف 1 في اول طلب و على المعرف 2 في ثاني طلب
        //عندها تصبح لدينا حالة deadlock
        //قمنا بترتيب ال id الخاصة بالمنتجات لمنع حدوث deadlock
        $requestedProductsIds = $requestedProducts->keys()->sort()->values()->toArray();
        try{
            //قمنا بعمل transaction من اجل تنفيذ جميع العمليات اللازمة معا
            //تنجح جميعها او تفشل جميعها
            return DB::transaction(function () use ($requestedProducts,$user,$requestedProductsIds) {
                //قفل محفظة اليوزر لتجنب حدوث race condition على الاموال
                $wallet = Wallet::where('user_id', $user->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                //قفل جميع المنتجات في الطلب الى حين معالجتها واتمام عملية الدفع
                $products = Product::whereIn('id', $requestedProductsIds)->orderBy('id')->lockForUpdate()->get();
                return $this->processOrder($user, $wallet, $requestedProducts, $products);
            });
        }
        catch (\Exception $exception)
        {
            return response()->json(['message' => $exception->getMessage(),'container' => gethostname(),],400);
        }
    }
    private function bringOrderedProducts(array $data):Collection
    {
        // إذا نفس المنتج تكرر بالطلب، منجمع الكميات تبعو
        return collect($data['products'])
            ->groupBy('id')
            ->map(function ($items) {
                return $items->sum('quantity');
            });

    }

    private function processOrder(User $user,Wallet $wallet,Collection $requestedProducts,Collection $products):JsonResponse
    {
        $totalPrice = $this->getTotalPrice($products, $requestedProducts);

        // فحص رصيد المحفظة
        if ($wallet->balance < $totalPrice) {
            throw new \Exception('you dont have enough balance');
        }

        // إنشاء الطلب
        $order = $this->orderService->store($user,$totalPrice);

        $this->decrementProductsAndCreateOrderItems($products,$requestedProducts,$order);

        // خصم الكميات وإنشاء عناصر الطلب
        $this->walletService->decrementAndCreateTransaction($wallet,$order,$totalPrice);

        GenerateInvoiceJob::dispatch($order->id)->afterCommit();

        return response()->json([
            'message' => 'payment success',
            'order_id' => $order->id,
            'total_price' => $totalPrice,
            'invoice_pdf' => 'you will find your invoice as pdf on: '.url("storage/invoices/order_{$order->id}/invoice.pdf"),
            'invoice_image' => 'you will find your invoice as image on: '.url("storage/invoices/order_{$order->id}/invoice.png"),
            'invoice_status' => 'processing',
            'container' => gethostname(),
        ]);
    }

    private function getTotalPrice(Collection $products, Collection $requestedProducts):float
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
        return $totalPrice;
    }

    private function decrementProductsAndCreateOrderItems(Collection $products, Collection $requestedProducts, Order $order):void
    {
        $orderItemsData = [];
        $now = Carbon::now();

        foreach ($products as $product) {
            $quantity = $requestedProducts[$product->id];

            $product->decrement('quantity', $quantity);

            $orderItemsData[] = [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $product->price,
                'total_price' => $product->price * $quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        OrderItem::insert($orderItemsData);

    }
    public function latestProducts()
    {
        $key = 'products:latest';
        $cache = Cache::store('redis');

        if ($cache->has($key)) {
            return $cache->get($key);
        }

        return $cache->lock($key . ':lock', 10)->block(5, function () use ($cache, $key) {
            if (!$cache->has($key)) {
                $this->storeLatestProductsInCache($key);
            }

            return $cache->get($key);
        });
    }
    public function cheapestProducts()
    {
        $key = 'products:cheapest';
        $cache = Cache::store('redis');

        if ($cache->has($key)) {
            return $cache->get($key);
        }

        return $cache->lock($key . ':lock', 10)->block(5, function () use ($cache, $key) {
            if (!$cache->has($key)) {
                $this->storeCheapestProductsInCache($key);
            }

            return $cache->get($key);
        });
    }

    public function mostOrderedProducts()
    {
        $key = 'products:popular:weekly';
        $cache = Cache::store('redis');

        if ($cache->has($key)) {
            return $cache->get($key);
        }

        return $cache->lock($key . ':lock', 10)->block(5, function () use ($cache, $key) {
            if (!$cache->has($key)) {
                $this->storeMostOrderedProductsInCache($key);
            }

            return $cache->get($key);
        });
    }

    private function storeMostOrderedProductsInCache(string $key):void
    {
        $now = now();

        $thisWeekStart = $now->copy()->startOfWeek();
        $thisWeekEnd = $now->copy()->endOfWeek();

        $lastWeekStart = $now->copy()->subWeek()->startOfWeek();
        $lastWeekEnd = $now->copy()->subWeek()->endOfWeek();

        $productStats = collect();

        // 1. منتجات هذا الأسبوع، مرتبة حسب عدد الـ items
        $thisWeekProducts = $this->getPopularProductsBetween(
            $thisWeekStart,
            $thisWeekEnd,
            10
        );

        foreach ($thisWeekProducts as $productId => $stats) {
            $productStats->put((int) $productId, [
                'orders_count' => (int) $stats['orders_count'],
                'items_count' => (int) $stats['items_count'],
                'source' => 'this_week',
            ]);
        }

        // 2. إذا ناقص، كمّل من الأسبوع الماضي بدون تكرار
        if ($productStats->count() < 10) {
            $lastWeekProducts = $this->getPopularProductsBetween(
                $lastWeekStart,
                $lastWeekEnd,
                10
            );

            foreach ($lastWeekProducts as $productId => $stats) {
                $productId = (int) $productId;

                if (!$productStats->has($productId)) {
                    $productStats->put($productId, [
                        'orders_count' => (int) $stats['orders_count'],
                        'items_count' => (int) $stats['items_count'],
                        'source' => 'last_week',
                    ]);
                }

                if ($productStats->count() >= 10) {
                    break;
                }
            }
        }

        // 3. إذا لسا ناقص، كمّل من منتجات عامة
        if ($productStats->count() < 10) {
            $missing = 10 - $productStats->count();

            $fallbackIds = Product::query()
                ->whereNotIn('id', $productStats->keys()->toArray())
                ->where('quantity', '>', 0)
                ->limit($missing)
                ->pluck('id');

            foreach ($fallbackIds as $productId) {
                $productStats->put((int) $productId, [
                    'orders_count' => 0,
                    'items_count' => 0,
                    'source' => 'fallback',
                ]);
            }
        }

        $productIds = $productStats->keys()->toArray();

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get()
            ->sortBy(function ($product) use ($productIds) {
                return array_search($product->id, $productIds);
            })
            ->values()
            ->map(function ($product) use ($productStats) {
                $stats = $productStats->get($product->id);

                $product->orders_count = $stats['orders_count'];
                $product->items_count = $stats['items_count'];
                $product->popular_source = $stats['source'];

                return $product;
            });
        $this->storeDataInCache($key,$products->toArray(),now()->addMinutes(30));

    }

    private function getPopularProductsBetween($start, $end, int $limit = 10)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->select('order_items.product_id')
            ->selectRaw('COUNT(DISTINCT orders.id) as orders_count')
            ->selectRaw('SUM(order_items.quantity) as items_count')
            ->groupBy('order_items.product_id')
            ->orderByDesc('items_count')
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->get()
            ->mapWithKeys(function ($row) {
                return [
                    (int) $row->product_id => [
                        'orders_count' => (int) $row->orders_count,
                        'items_count' => (int) $row->items_count,
                    ],
                ];
            });
    }
    public function storeDataInCache(string $key, array $data,$ttl=null):void
    {
        Cache::store('redis')->put(
            $key,
            $data,
            $ttl
        );
    }

    private function storeCheapestProductsInCache(string $key):void
    {
        $cheapestProducts = Product::orderBy('price')->limit(10)->get()->toArray();
        $this->storeDataInCache($key,$cheapestProducts);
    }
    private function storeLatestProductsInCache(string $key):void
    {
        $data = Product::latest()->limit(10)->get()->toArray();
        $this->storeDataInCache($key,$data);
    }





}
