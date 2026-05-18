<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $totalSales = 0;
        $totalOrders = 0;

        Order::where('status', 'paid')
            ->whereDate('created_at',$this->date)
            ->chunkById(100, function ($orders) use (&$totalSales, &$totalOrders) {

                foreach ($orders as $order) {

                    $totalSales += $order->total_price;

                    $totalOrders++;
                }
            });

        $report = [
            'date' => $this->date,
            'total_sales' => $totalSales,
            'total_orders' => $totalOrders,
            'processed_at' => now()->toDateTimeString(),
        ];

        Storage::disk('public')->put(
            "daily_reports/" . $this->date . ".json",
            json_encode($report, JSON_PRETTY_PRINT)
        );
    }
}
