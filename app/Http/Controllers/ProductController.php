<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderingRequest;
use App\Jobs\GenerateInvoiceJob;
use App\Jobs\ProcessDailySales;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function order(OrderingRequest $request):JsonResponse
    {
        //قمنا بعمل validation للطلبات للتاكد بانها موجودة في قاعدة البيانات
        $data = $request->validated();
        $user = User::where('email',$request->email)->firstOrFail();
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
                $products = Product::whereIn('id', $requestedProductsIds)->lockForUpdate()->get();
                return $this->toDatabase($user, $wallet, $requestedProducts, $products);
            });
        }
        catch (\Exception $exception)
        {
            return response()->json(['message' => $exception->getMessage(),'container' => gethostname(),],400);
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
        GenerateInvoiceJob::dispatch($order->id);

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

    public function getDailySales():JsonResponse
    {
        $date = today()->toDateString();
        ProcessDailySales::dispatch($date);
        return response()->json([
            'message' => 'processing daily sales',
            'status' => 'processing',
            'url' => url("api/daily_reports/" . $date),
        ], 202);
    }

    public function getReport(string $name): JsonResponse
    {
        $filePath = "daily_reports/" . $name . ".json";

        if (! Storage::disk('public')->exists($filePath)) {
            return response()->json([
                'message' => 'report is still processing or not found',
            ], 202);
        }

        $file = Storage::disk('public')->get($filePath);

        return response()->json([
            'message' => 'daily sales report',
            'status' => 'ready',
            'data' => json_decode($file, true),
        ]);
    }


}
