<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Notifications\LowStockNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class NotifyLowStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $productId,
        public readonly int $currentStock,
    ) {}

    public function handle(): void
    {
        $product = Product::find($this->productId);
        if (! $product) {
            return;
        }

        $admins = User::role('admin')->get();
        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new LowStockNotification($product, $this->currentStock));
    }
}
