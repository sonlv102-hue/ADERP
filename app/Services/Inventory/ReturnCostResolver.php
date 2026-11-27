<?php

namespace App\Services\Inventory;

use App\Models\PurchaseReturnItem;
use App\Models\SalesReturnItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Giá trị kho của hàng trả lấy từ giao dịch GỐC (A4), không dùng giá bình quân hiện tại hay cost_price (gồm VAT):
 *  - Trả NCC: đơn giá nhập (chưa VAT) bình quân của các phiếu nhập đã xác nhận cho đúng dòng PO, tại kho trả.
 *  - Khách trả: giá vốn bình quân của các phiếu xuất đã xác nhận cho đúng dòng đơn hàng.
 * Không xác định được giao dịch gốc → báo lỗi rõ ràng, không đoán giá.
 */
class ReturnCostResolver
{
    public function purchaseReturnUnitCost(PurchaseReturnItem $item, int $warehouseId): float
    {
        $rows = DB::table('stock_entry_items as i')
            ->join('stock_entries as e', 'e.id', '=', 'i.stock_entry_id')
            ->where('e.status', 'confirmed')
            ->where('e.warehouse_id', $warehouseId)
            ->where('i.purchase_order_item_id', $item->purchase_order_item_id)
            ->where('i.product_id', $item->product_id)
            ->selectRaw('SUM(i.quantity) q, SUM(i.quantity * COALESCE(i.unit_cost, i.unit_price, 0)) v, SUM(CASE WHEN i.project_id IS NOT NULL THEN 1 ELSE 0 END) project_lines')
            ->first();

        if (! $rows || (float) $rows->q <= 0) {
            throw new RuntimeException("Không tìm thấy phiếu nhập đã xác nhận tại kho này cho dòng PO của sản phẩm [{$item->product?->code}] — không xác định được giá trị hàng trả.");
        }
        if ((int) $rows->project_lines > 0) {
            throw new RuntimeException("Sản phẩm [{$item->product?->code}] được nhập cho dự án (lô FIFO). Trả NCC hàng dự án chưa được hỗ trợ tự động — cần kế toán xử lý.");
        }

        return round((float) $rows->v / (float) $rows->q, 4);
    }

    public function salesReturnUnitCost(SalesReturnItem $item): float
    {
        $row = DB::table('stock_exit_items as i')
            ->join('stock_exits as x', 'x.id', '=', 'i.stock_exit_id')
            ->where('x.status', 'confirmed')
            ->where('i.order_item_id', $item->order_item_id)
            ->where('i.product_id', $item->product_id)
            ->selectRaw('SUM(i.quantity) q, SUM(COALESCE(i.total_cost, 0)) v')
            ->first();

        if (! $row || (float) $row->q <= 0) {
            throw new RuntimeException("Không tìm thấy phiếu xuất đã xác nhận cho dòng đơn hàng của sản phẩm [{$item->product?->code}] — không xác định được giá vốn hàng trả.");
        }

        return round((float) $row->v / (float) $row->q, 4);
    }
}
