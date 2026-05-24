<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use App\Models\Product;

class LowStockNotification extends Notification
{
    public function __construct(public Product $product, public int $currentStock) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'low_stock',
            'title'   => 'Tồn kho thấp',
            'message' => "Sản phẩm \"{$this->product->name}\" còn {$this->currentStock} trong kho",
            'url'     => "/catalog/products/{$this->product->id}",
            'icon'    => 'exclamation-triangle',
            'color'   => 'yellow',
        ];
    }
}
