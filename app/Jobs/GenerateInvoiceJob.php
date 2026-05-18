<?php

namespace App\Jobs;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class GenerateInvoiceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $orderId
    ) {}

    public function handle(): void
    {
        $order = Order::with(['user', 'items.product'])
            ->findOrFail($this->orderId);

        $folder = "invoices/order_{$order->id}";

        Storage::disk('public')->makeDirectory($folder);

        $pdfPath = "{$folder}/invoice.pdf";
        $imagePath = "{$folder}/invoice.png";

        $pdf = Pdf::loadView('invoice', [
            'order' => $order,
            'user' => $order->user,
        ]);

        Storage::disk('public')->put($pdfPath, $pdf->output());

        $height = 350 + ($order->items->count() * 45);

        $image = Image::canvas(900, $height, '#ffffff');

        $y = 40;

        $image->text("Invoice #{$order->num}", 40, $y, function ($font) {
            $font->size(28);
        });

        $y += 50;

        $image->text("Customer: {$order->user->name}", 40, $y, function ($font) {
            $font->size(18);
        });

        $y += 35;

        $image->text("Product", 40, $y, fn ($font) => $font->size(16));
        $image->text("Qty", 360, $y, fn ($font) => $font->size(16));
        $image->text("Unit Price", 470, $y, fn ($font) => $font->size(16));
        $image->text("Total", 650, $y, fn ($font) => $font->size(16));

        $y += 35;

        foreach ($order->items as $item) {
            $image->text($item->product->name, 40, $y, fn ($font) => $font->size(15));
            $image->text((string) $item->quantity, 370, $y, fn ($font) => $font->size(15));
            $image->text((string) $item->unit_price, 480, $y, fn ($font) => $font->size(15));
            $image->text((string) $item->total_price, 650, $y, fn ($font) => $font->size(15));

            $y += 40;
        }

        $y += 30;

        $image->text("Final Total: {$order->total_price}", 40, $y, function ($font) {
            $font->size(24);
        });

        Storage::disk('public')->put(
            $imagePath,
            (string) $image->encode('png')
        );
    }
}
