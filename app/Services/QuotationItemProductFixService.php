<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Models\Product;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QuotationItemProductFixService
{
    /**
     * Sửa product_id sai trên dòng hàng của báo giá đã hủy — trạng thái duy nhất mà form sửa
     * thông thường (QuotationController::edit()/update()) không cho admin sửa. Các trạng thái
     * khác admin đã sửa được toàn bộ qua form thường, không dùng service này.
     */
    public function fixProductLink(QuotationItem $item, int $newProductId, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($item, $newProductId, $reason, $actor) {
            $item = QuotationItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $quotation = $item->quotation()->lockForUpdate()->firstOrFail();

            if ($quotation->status !== QuotationStatus::Cancelled) {
                throw new RuntimeException('Chỉ áp dụng cho báo giá đã hủy — các trạng thái khác đã sửa được qua form chỉnh sửa thông thường (dành cho admin).');
            }

            if ($newProductId === $item->product_id) {
                throw new RuntimeException('Sản phẩm mới trùng sản phẩm hiện tại.');
            }

            $newProduct = Product::findOrFail($newProductId);

            $old = [
                'product_id' => $item->product_id,
                'name'       => $item->name,
            ];

            $item->update([
                'product_id' => $newProduct->id,
                'service_id' => null,
                'item_type'  => 'product',
                'name'       => $newProduct->name,
            ]);

            activity()
                ->performedOn($quotation)
                ->causedBy($actor)
                ->withProperties([
                    'quotation_item_id' => $item->id,
                    'old_product_id'    => $old['product_id'],
                    'new_product_id'    => $newProduct->id,
                    'old_name'          => $old['name'],
                    'new_name'          => $newProduct->name,
                    'reason'            => $reason,
                ])
                ->log("Sửa sản phẩm dòng hàng #{$item->id} trên báo giá {$quotation->code}: {$reason}");

            return [
                'old_product_id' => $old['product_id'],
                'new_product_id' => $newProduct->id,
                'old_name'       => $old['name'],
                'new_name'       => $newProduct->name,
            ];
        });
    }
}
