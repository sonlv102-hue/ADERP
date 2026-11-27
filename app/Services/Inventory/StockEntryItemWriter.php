<?php

namespace App\Services\Inventory;

use App\Models\ProductSerial;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * Ghi dòng hàng khi SỬA phiếu nhập nháp mà không làm mất truy vết movement cũ.
 *  - Dòng cùng sản phẩm được giữ nguyên ID (cập nhật tại chỗ) → movement gốc / đảo / xác nhận lại
 *    luôn trỏ cùng source_item_id.
 *  - Dòng bị bỏ thật sự: lưu snapshot (dòng + movement liên quan) vào activity log trước khi xóa.
 *  - Mọi thay đổi số lượng/đơn giá của dòng đã có movement được ghi log trước/sau.
 * Serial của phiếu nháp được ghi lại toàn bộ như trước (không có giá trị kế toán).
 */
class StockEntryItemWriter
{
    private Collection $pool;

    public function __construct(private StockEntry $entry)
    {
        $this->pool = $entry->items()->orderBy('id')->get();
        ProductSerial::whereIn('stock_entry_item_id', $this->pool->pluck('id'))->delete();
    }

    public function put(array $attrs): StockEntryItem
    {
        $idx = $this->pool->search(fn (StockEntryItem $i) => (int) $i->product_id === (int) $attrs['product_id']);
        if ($idx === false) {
            return $this->entry->items()->create($attrs);
        }
        $item = $this->pool->pull($idx);
        $item->fill($attrs);
        if ($item->isDirty() && $this->movementIds($item)) {
            $this->log($item, 'Sửa dòng hàng #' . $item->id, [
                'old' => array_intersect_key($item->getOriginal(), $item->getDirty()),
                'new' => $item->getDirty(),
            ]);
        }
        $item->save();
        return $item;
    }

    /** Xóa các dòng cũ không còn trong phiếu (gọi sau khi put() hết dòng mới). */
    public function finish(): void
    {
        foreach ($this->pool as $item) {
            $this->log($item, 'Xóa dòng hàng #' . $item->id, ['deleted_item' => $item->getAttributes()]);
            $item->delete();
        }
        $this->pool = collect();
    }

    private function movementIds(StockEntryItem $item): array
    {
        return StockMovement::where('source_type', StockEntry::class)->where('source_id', $this->entry->id)
            ->where('source_item_id', $item->id)->orderBy('id')->pluck('id')->all();
    }

    private function log(StockEntryItem $item, string $what, array $props): void
    {
        activity('stock_entry_item')->performedOn($this->entry)->causedBy(auth()->user())
            ->withProperties($props + ['stock_entry_item_id' => $item->id, 'movement_ids' => $this->movementIds($item)])
            ->log("{$what} khi sửa phiếu {$this->entry->code}");
    }
}
