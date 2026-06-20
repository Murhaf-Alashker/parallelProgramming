<?php

namespace App\Observers;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class ProductObserver
{
    /**
     * Handle the Product "created" event.
     */
    private function clearProductCache(): void
    {
        $cache = Cache::store('redis');

        $cache->forget('products:latest');
        $cache->forget('products:cheapest');
        $cache->forget('categories:products');
    }
    public function created(Product $product): void
    {
        $this->clearProductCache();
    }

    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        $this->clearProductCache();
        $changed = array_keys($product->getChanges());

        $realChanges = array_diff($changed, ['quantity', 'updated_at']);

        if (empty($realChanges)) {
            return;
        }
        Cache::store('redis')->forget('products:popular:weekly');
    }

    /**
     * Handle the Product "deleted" event.
     */
    public function deleted(Product $product): void
    {
        $this->clearProductCache();
        Cache::store('redis')->forget('products:popular:weekly');
    }

    /**
     * Handle the Product "restored" event.
     */
    public function restored(Product $product): void
    {
        $this->clearProductCache();
    }

    /**
     * Handle the Product "force deleted" event.
     */
    public function forceDeleted(Product $product): void
    {
        $this->clearProductCache();
    }
}
