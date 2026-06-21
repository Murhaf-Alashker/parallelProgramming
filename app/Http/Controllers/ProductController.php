<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderingRequest;
use App\Jobs\ProcessDailySales;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    protected ProductService $productService;
    protected CategoryService $categoryService;
    public function __construct(ProductService $productService, CategoryService $categoryService){
        $this->productService = $productService;
        $this->categoryService = $categoryService;
    }
    public function order(OrderingRequest $request):JsonResponse
    {
        //قمنا بعمل validation للطلبات للتاكد بانها موجودة في قاعدة البيانات
        $data = $request->validated();
        $user = User::where('email',$data['email'])->firstOrFail();
        return $this->productService->order($user,$data);

    }

    public function getDailySales(string $date = null):JsonResponse
    {

        $date = $date ?? today()->toDateString();
        $cache = Cache::store('redis');

        $stateKey = "report:{$date}:state";
        $lockKey = "lock:report:{$date}:dispatch";

        $cache->lock($lockKey, 10)->block(3, function () use ($cache, $stateKey, $date) {

            $state = $cache->get($stateKey);

            if ($state !== 'processing' && $state !== 'ready') {
                $cache->put($stateKey, 'processing');

                ProcessDailySales::dispatch($date);
            }
        });
        return response()->json([
            'message' => 'processing daily sales',
            'status' => 'processing',
            'url' => url("api/daily_reports/" . $date),
        ], 202);
    }

    public function getReport(string $date): JsonResponse
    {
        $validator = Validator::make(
            ['date' => $date],
            [
                'date' => ['required', 'date_format:Y-m-d'],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid date format',
                'required_format' => 'YYYY-MM-DD',
                'example' => '2026-06-20',
                'errors' => $validator->errors(),
            ], 422);
        }
        $cache = Cache::store('redis');
        $key = "report:{$date}:state";
        $filePath = "daily_reports/" . $date . ".json";
        $state = $cache->get($key);
        if($state == null){
            return $this->getDailySales($date);
        }


        if($state == 'processing'){
            return response()->json([
                'message' => 'processing daily sales',
                'status' => 'processing',
                'url' => url("api/daily_reports/" . $date),
            ], 202);
        }
        $dataKey = "report:{$date}";
        if (!$cache->has($dataKey)){
            $cache->lock("lock:report:{$date}:cache-fill", 10)->block(3, function () use ($cache, $dataKey, $filePath) {

                if (!$cache->has($dataKey)) {
                    $file = Storage::disk('public')->get($filePath);
                    $cache->put($dataKey, json_decode($file, true), 3600);
                }
            });
        }

        return response()->json([
            'message' => 'daily sales report',
            'status' => 'ready',
            'data' => $cache->get("report:{$date}"),
        ]);
    }

    public function homePage():array
    {
        return [
            'latest_products' => $this->latestProducts(),
            'most_ordered_products' => $this->mostOrderedProducts(),
            'products_by_categories' => $this->productsByCategory(),
            'cheapest_products' => $this->cheapestProducts()
        ];
    }

    public function index(): JsonResponse
    {
        $products = Product::with('category')
            ->latest('id')
            ->paginate(request('per_page', 15));

        return response()->json([
            'message' => 'products retrieved successfully',
            'data' => $products,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'image' => ['nullable', 'file', 'max:2048'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
        ]);

        $product = Product::create($data);

        return response()->json([
            'message' => 'product created successfully',
            'data' => $product->load('category'),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'message' => 'product retrieved successfully',
            'data' => $product->load(['category']),
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'image' => ['nullable', 'string', 'max:2048'],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
        ]);

        $product->update($data);

        return response()->json([
            'message' => 'product updated successfully',
            'data' => $product->fresh()->load('category'),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json([
            'message' => 'product deleted successfully',
        ]);
    }

    public function latestProducts()
    {
        return $this->productService->latestProducts();
    }

    public function productsByCategory()
    {
        return $this->categoryService->productsByCategory();
    }

    public function cheapestProducts()
    {
        return $this->productService->cheapestProducts();
    }

    public function mostOrderedProducts()
    {
        return $this->productService->mostOrderedProducts();
    }


}
