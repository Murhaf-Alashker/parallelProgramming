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
use Illuminate\Support\Facades\Storage;

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
            'data' => $product->load(['category', 'orderItems.order']),
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
