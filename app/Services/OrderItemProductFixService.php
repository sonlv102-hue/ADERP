<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockExitItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderItemProductFixService
{
    /**
     * Sửa product_id sai trên dòng hàng của đơn đã khóa (Completed/Cancelled), nơi form sửa
     * thông thường (OrderController::update()) đã chặn. Đơn chưa khóa thì dùng form thường,
     * không qua service này.
     */
    public function fixProductLink(OrderItem $item, int $newProductId, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($item, $newProductId, $reason, $actor) {
            $item = OrderItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $order = $item->order()->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                throw new RuntimeException('Đơn hàng đang ở trạng thái có thể sửa qua form chỉnh sửa thông thường.');
            }

            if ((float) $item->delivered_quantity > 0) {
                throw new RuntimeException('Dòng hàng đã có số lượng giao — không thể đổi sản phẩm.');
            }

            if (StockExitItem::where('order_item_id', $item->id)->exists()) {
                throw new RuntimeException('Dòng hàng đã liên kết phiếu xuất kho — không thể đổi sản phẩm.');
            }

            if ($newProductId === $item->product_id) {
                throw new RuntimeException('Sản phẩm mới trùng sản phẩm hiện tại.');
            }

            $newProduct = Product::with('category:id,revenue_account_code')->findOrFail($newProductId);

            $old = [
                'product_id' => $item->product_id,
                'name'       => $item->name,
            ];

            $vatDiv = 1 + (float) ($newProduct->vat_percent ?? 0) / 100;

            $item->update([
                'product_id'            => $newProduct->id,
                'service_id'            => null,
                'name'                  => $newProduct->name,
                'unit_cogs'             => round((float) $newProduct->cost_price / $vatDiv, 2),
                'unit_cogs_source'      => 'snapshot',
                'revenue_account_code'  => $this->resolveRevenueAccount(
                    $newProduct->revenue_account_code,
                    $newProduct->category?->revenue_account_code,
                    $newProduct->item_type,
                ),
            ]);

            activity()
                ->performedOn($order)
                ->causedBy($actor)
                ->withProperties([
                    'order_item_id'  => $item->id,
                    'old_product_id' => $old['product_id'],
                    'new_product_id' => $newProduct->id,
                    'old_name'       => $old['name'],
                    'new_name'       => $newProduct->name,
                    'reason'         => $reason,
                ])
                ->log("Sửa sản phẩm dòng hàng #{$item->id} trên đơn {$order->code}: {$reason}");

            return [
                'old_product_id' => $old['product_id'],
                'new_product_id' => $newProduct->id,
                'old_name'       => $old['name'],
                'new_name'       => $newProduct->name,
            ];
        });
    }

    // Giống resolveRevenueAccount() private trong OrderController — giữ đồng nhất công thức snapshot.
    private function resolveRevenueAccount(?string $productAccount, ?string $categoryAccount, ?string $itemType): ?string
    {
        if ($productAccount) return $productAccount;
        if ($categoryAccount) return $categoryAccount;
        return match ($itemType ?? 'goods') {
            'goods'   => '5111',
            'service' => '5113',
            default   => null,
        };
    }
}
