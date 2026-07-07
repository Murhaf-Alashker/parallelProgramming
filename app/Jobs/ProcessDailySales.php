<?php

namespace App\Jobs;

use App\Models\Order;
use App\Support\StructuredPerformanceLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessDailySales implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public $date)
    {
    }

    private function trace(string $name, \Closure $callback, array $meta = [])
    {
        return app(StructuredPerformanceLogger::class)->span($name, $callback, $meta);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $logger = app(StructuredPerformanceLogger::class);

        $logger->startJob('ProcessDailySales', [
            'date' => $this->date,
        ]);

        try {
            $this->trace('ProcessDailySales::handle', function () {
                $cache = $this->trace('ProcessDailySales::getRedisCacheStore', function () {
                    return Cache::store('redis');
                });

                $key = $this->trace('ProcessDailySales::buildCacheKey', function () {
                    return "report:{$this->date}";
                }, [
                    'date' => $this->date,
                ]);

                $totalSales = 0;
                $totalOrders = 0;

                $this->trace('ProcessDailySales::queryPaidOrdersChunkById', function () use (&$totalSales, &$totalOrders) {
                    Order::where('status', 'paid')
                        ->whereDate('created_at', $this->date)
                        ->chunkById(100, function ($orders) use (&$totalSales, &$totalOrders) {
                            $this->trace('ProcessDailySales::processOrdersChunk', function () use ($orders, &$totalSales, &$totalOrders) {
                                foreach ($orders as $order) {
                                    $totalSales += $order->total_price;

                                    $totalOrders++;
                                }
                            }, [
                                'chunk_count' => $orders->count(),
                            ]);
                        });
                }, [
                    'date' => $this->date,
                    'chunk_size' => 100,
                ]);

                $report = $this->trace('ProcessDailySales::buildReportArray', function () use (&$totalSales, &$totalOrders) {
                    return [
                        'date' => $this->date,
                        'total_sales' => $totalSales,
                        'total_orders' => $totalOrders,
                        'processed_at' => now()->toDateTimeString(),
                    ];
                }, [
                    'date' => $this->date,
                    'total_sales' => $totalSales,
                    'total_orders' => $totalOrders,
                ]);

                $this->trace('ProcessDailySales::cacheReport', function () use ($cache, $key, $report) {
                    $cache->put($key, $report, 3600);
                }, [
                    'key' => $key,
                    'ttl_seconds' => 3600,
                ]);

                $this->trace('ProcessDailySales::storeReportJsonFile', function () use ($report) {
                    Storage::disk('public')->put(
                        "daily_reports/" . $this->date . ".json",
                        json_encode($report, JSON_PRETTY_PRINT)
                    );
                }, [
                    'path' => "daily_reports/" . $this->date . ".json",
                ]);

                $this->trace('ProcessDailySales::setReportStateReady', function () use ($cache) {
                    $cache->put("report:{$this->date}:state", 'ready');
                }, [
                    'key' => "report:{$this->date}:state",
                    'state' => 'ready',
                ]);
            });
        } catch (\Throwable $e) {
            $logger->recordException($e);

            throw $e;
        } finally {
            $logger->finish();
        }
    }
}
