<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderingRequest;
use App\Jobs\GenerateInvoiceJob;
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
        GenerateInvoiceJob::dispatch($order->id);

        return response()->json([
            'message' => 'payment success',
            'order_id' => $order->id,
            'total_price' => $totalPrice,
            'invoice_pdf' => 'you will find your invoice as pdf on: '.url("storage/invoices/order_{$order->id}/invoice.pdf"),
            'invoice_image' => 'you will find your invoice as image on: '.url("storage/invoices/order_{$order->id}/invoice.png"),
            'invoice_status' => 'processing',
        ]);
    }

//    private function generateInvoice($order, User $user): array
//    {
//        $folder = "invoices/order_{$order->id}";
//
//        Storage::disk('public')->makeDirectory($folder);
//
//        $pdfPath = "{$folder}/invoice.pdf";
//        $imagePath = "{$folder}/invoice.png";
//
//        // توليد PDF
//        $pdf = Pdf::loadView('invoice', [
//            'order' => $order,
//            'user' => $user,
//        ]);
//
//        Storage::disk('public')->put($pdfPath, $pdf->output());
//
//        // توليد صورة
//        $height = 350 + ($order->items->count() * 45);
//
//        $image = Image::canvas(900, $height, '#ffffff');
//
//        $y = 40;
//
//        $image->text("Invoice #{$order->num}", 40, $y, function ($font) {
//            $font->size(28);
//        });
//
//        $y += 50;
//
//        $image->text("Customer: {$user->name}", 40, $y, function ($font) {
//            $font->size(18);
//        });
//
//        $y += 35;
//
//        $image->text("Product", 40, $y, function ($font) {
//            $font->size(16);
//        });
//
//        $image->text("Qty", 360, $y, function ($font) {
//            $font->size(16);
//        });
//
//        $image->text("Unit Price", 470, $y, function ($font) {
//            $font->size(16);
//        });
//
//        $image->text("Total", 650, $y, function ($font) {
//            $font->size(16);
//        });
//
//        $y += 35;
//
//        foreach ($order->items as $item) {
//            $image->text($item->product->name, 40, $y, function ($font) {
//                $font->size(15);
//            });
//
//            $image->text((string) $item->quantity, 370, $y, function ($font) {
//                $font->size(15);
//            });
//
//            $image->text((string) $item->unit_price, 480, $y, function ($font) {
//                $font->size(15);
//            });
//
//            $image->text((string) $item->total_price, 650, $y, function ($font) {
//                $font->size(15);
//            });
//
//            $y += 40;
//        }
//
//        $y += 30;
//
//        $image->text("Final Total: {$order->total_price}", 40, $y, function ($font) {
//            $font->size(24);
//        });
//
//        Storage::disk('public')->put(
//            $imagePath,
//            (string) $image->encode('png')
//        );
//
//        return [
//            'pdf_url' => asset("storage/{$pdfPath}"),
//            'image_url' => asset("storage/{$imagePath}"),
//        ];
//    }
}
