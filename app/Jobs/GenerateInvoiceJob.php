<?php

namespace App\Jobs;

use App\Models\Order;
use App\Support\StructuredPerformanceLogger;
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

    private function trace(string $name, \Closure $callback, array $meta = [])
    {
        return app(StructuredPerformanceLogger::class)->span($name, $callback, $meta);
    }

    public function handle(): void
    {
        $logger = app(StructuredPerformanceLogger::class);

        $logger->startJob('GenerateInvoiceJob', [
            'order_id' => $this->orderId,
        ]);

        try {
            $this->trace('GenerateInvoiceJob::handle', function () {
                $order = $this->trace('GenerateInvoiceJob::loadOrderWithRelations', function () {
                    return Order::with(['user', 'items.product'])
                        ->findOrFail($this->orderId);
                }, [
                    'order_id' => $this->orderId,
                ]);

                $folder = $this->trace('GenerateInvoiceJob::buildFolderPath', function () use ($order) {
                    return "invoices/order_{$order->id}";
                }, [
                    'order_id' => $order->id,
                ]);

                $this->trace('GenerateInvoiceJob::makeStorageDirectory', function () use ($folder) {
                    Storage::disk('public')->makeDirectory($folder);
                }, [
                    'folder' => $folder,
                ]);

                $pdfPath = $this->trace('GenerateInvoiceJob::buildPdfPath', function () use ($folder) {
                    return "{$folder}/invoice.pdf";
                }, [
                    'folder' => $folder,
                ]);

                $imagePath = $this->trace('GenerateInvoiceJob::buildImagePath', function () use ($folder) {
                    return "{$folder}/invoice.png";
                }, [
                    'folder' => $folder,
                ]);

                $pdf = $this->trace('GenerateInvoiceJob::renderPdfView', function () use ($order) {
                    return Pdf::loadView('invoice', [
                        'order' => $order,
                        'user' => $order->user,
                    ]);
                }, [
                    'order_id' => $order->id,
                    'items_count' => $order->items->count(),
                ]);

                $this->trace('GenerateInvoiceJob::storePdf', function () use ($pdfPath, $pdf) {
                    Storage::disk('public')->put($pdfPath, $pdf->output());
                }, [
                    'pdf_path' => $pdfPath,
                ]);

                $height = $this->trace('GenerateInvoiceJob::calculateImageHeight', function () use ($order) {
                    return 350 + ($order->items->count() * 45);
                }, [
                    'items_count' => $order->items->count(),
                ]);

                $image = $this->trace('GenerateInvoiceJob::createCanvas', function () use ($height) {
                    return Image::canvas(900, $height, '#ffffff');
                }, [
                    'width' => 900,
                    'height' => $height,
                ]);

                $y = 40;

                $this->trace('GenerateInvoiceJob::drawInvoiceHeader', function () use ($image, $order, &$y) {
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
                }, [
                    'order_id' => $order->id,
                ]);

                $this->trace('GenerateInvoiceJob::drawItems', function () use ($order, $image, &$y) {
                    foreach ($order->items as $item) {
                        $image->text($item->product->name, 40, $y, fn ($font) => $font->size(15));
                        $image->text((string) $item->quantity, 370, $y, fn ($font) => $font->size(15));
                        $image->text((string) $item->unit_price, 480, $y, fn ($font) => $font->size(15));
                        $image->text((string) $item->total_price, 650, $y, fn ($font) => $font->size(15));

                        $y += 40;
                    }
                }, [
                    'items_count' => $order->items->count(),
                ]);

                $this->trace('GenerateInvoiceJob::drawFinalTotal', function () use ($order, $image, &$y) {
                    $y += 30;

                    $image->text("Final Total: {$order->total_price}", 40, $y, function ($font) {
                        $font->size(24);
                    });
                }, [
                    'order_id' => $order->id,
                    'total_price' => $order->total_price,
                ]);

                $this->trace('GenerateInvoiceJob::storeImage', function () use ($imagePath, $image) {
                    Storage::disk('public')->put(
                        $imagePath,
                        (string) $image->encode('png')
                    );
                }, [
                    'image_path' => $imagePath,
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
