<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

class CategoryService
{
    /**
     * Create a new class instance.
     */
    protected productService  $productService;
    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function productsByCategory()
    {
        $key = 'categories:products';
        $cache = Cache::store('redis');
        if($cache->has($key)){
            return $cache->get($key);
        }
        return $cache->lock($key . ':lock', 10)->block(5, function () use ($cache, $key) {
            if(!Cache::store('redis')->has($key)){
                $this->storeCategoriesProductsInCache($key);
            }
            return Cache::store('redis')->get($key);
        });

    }

    private function storeCategoriesProductsInCache(string $key): void
    {
        $data = Category::with([
            'products' => function ($q) {
                $q->latest()->limit(10);
            }
        ])->get()->toArray();
        $this->productService->storeDataInCache($key, $data);

    }
}
