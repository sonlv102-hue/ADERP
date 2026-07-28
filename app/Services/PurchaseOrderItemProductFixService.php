<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseOrderItemProductFixService
{
    /**
     * Sửa product_id sai trên dòng hàng của đơn mua không còn ở trạng thái Draft (form sửa
     * thông thường đã chặn). Chặn cứng nếu dòng hàng đã có bất kỳ stock_entry_items nào tham
     * chiếu — kể cả đã hủy/thu hồi — vì StockEntryItem.product_id độc lập, không tự đồng bộ
     * theo purchase_order_item, và các row này không bị xóa khi hủy phiếu nhập kho.
     */
    public function fixProductLink(PurchaseOrderItem $item, int $newProductId, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($item, $newProductId, $reason, $actor) {
            $item = PurchaseOrderItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $po = $item->purchaseOrder()->lockForUpdate()->firstOrFail();

            if ($po->status === PurchaseOrderStatus::Draft) {
                throw new RuntimeException('Đơn mua ở trạng thái nháp — sửa qua form chỉnh sửa thông thường.');
            }

            if ($item->stockEntryItems()->exists()) {
                throw new RuntimeException('Dòng hàng đã có phiếu nhập kho tham chiếu (kể cả đã hủy) — không thể đổi sản phẩm. Vui lòng tạo dòng mới hoặc liên hệ kế toán để xử lý thủ công.');
            }

            if ($newProductId === $item->product_id) {
                throw new RuntimeException('Sản phẩm mới trùng sản phẩm hiện tại.');
            }

            $newProduct = Product::findOrFail($newProductId);

            $old = ['product_id' => $item->product_id];

            $item->update(['product_id' => $newProduct->id]);

            activity()
                ->performedOn($po)
                ->causedBy($actor)
                ->withProperties([
                    'purchase_order_item_id' => $item->id,
                    'old_product_id'         => $old['product_id'],
                    'new_product_id'         => $newProduct->id,
                    'reason'                 => $reason,
                ])
                ->log("Sửa sản phẩm dòng hàng #{$item->id} trên đơn mua {$po->code}: {$reason}");

            return [
                'old_product_id' => $old['product_id'],
                'new_product_id' => $newProduct->id,
            ];
        });
    }
}
