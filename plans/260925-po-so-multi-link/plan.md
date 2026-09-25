# PO ↔ SO Many-to-Many Link + Line-Item Allocation

**Ngày:** 2026-09-25
**Yêu cầu gốc:** 1 Đơn mua hàng (PO) hiện chỉ liên kết được 1 Đơn hàng bán (SO). Cần đổi thành nhiều-nhiều (kể cả khác khách hàng), xem được chiều ngược lại từ SO, và phân bổ theo dòng hàng + số lượng giữa PO và SO.

**Quyết định đã chốt với user:**
- Làm cả line-item allocation (quantity) trong đợt này, không để phase 2.
- Xóa hẳn cột `purchase_orders.order_id` cũ sau khi migrate dữ liệu (không giữ deprecated).

## Hiện trạng (đã rà soát, xem chi tiết agent report trong session)

- Link hiện tại: `purchase_orders.order_id` (nullable FK → `orders.id`, `nullOnDelete`, migration `2026_06_03_900040`). Dùng ở đúng 1 chỗ: `PurchaseOrderController` (create/store/show/edit/update) + `PurchaseOrder::order()` (belongsTo) + `Form.vue`/`Show.vue` của PO.
- Chiều ngược lại **đã có sẵn**: `Order::purchaseOrders(): HasMany` + UI bảng "Đơn mua hàng liên kết" tại `Sales/Orders/Show.vue:297-334` (lặp qua mảng `order.purchase_orders`, không cần sửa nếu quan hệ đổi sang `belongsToMany` nhưng vẫn trả về Collection).
- Pattern junction có sẵn để nhân bản: `stock_exit_purchase_orders` (migration `2026_06_20_900141`) — bảng pivot đơn giản, `cascadeOnDelete` 2 chiều, unique cặp, `attach()`/`sync()`.
- Pattern allocation có sẵn để nhân bản: `stock_exit_item_lot_allocations` (migration `2026_06_13_900077`) — bảng allocation con với `allocated_qty`, `unit_cost`, `amount`, cột `voided_at` (soft-void thay vì xóa cứng).
- `purchase_order_items` và `order_items` hiện **không có cột liên kết chéo nào** (đã grep xác nhận).
- Không có report/export/service kế toán nào phụ thuộc `purchase_orders.order_id` → phạm vi xóa cột an toàn.
- Migration sequence tiếp theo: `900244` (mới nhất hiện tại là `2026_09_16_900243`).

## Phase 1 — Header-level M2M (PO ↔ SO)

### 1.1 Migration `2026_09_25_900244_create_purchase_order_orders_table.php`
```php
Schema::create('purchase_order_orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
    $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
    $table->timestamps();
    $table->unique(['purchase_order_id', 'order_id']);
});

// Migrate dữ liệu cũ trước khi xóa cột
DB::table('purchase_orders')->whereNotNull('order_id')->select('id', 'order_id')
    ->each(fn ($row) => DB::table('purchase_order_orders')->insert([
        'purchase_order_id' => $row->id,
        'order_id' => $row->order_id,
        'created_at' => now(), 'updated_at' => now(),
    ]));
```
Down: drop bảng (không khôi phục dữ liệu — chấp nhận, vì đây là down migration).

### 1.2 Migration `2026_09_25_900245_drop_order_id_from_purchase_orders_table.php`
Migration riêng (tách khỏi 900244) để nếu cần rollback nhanh giai đoạn migrate dữ liệu vẫn có thể revert độc lập. Drop FK + cột `order_id`. Down: thêm lại cột (không khôi phục data — ghi rõ trong comment).

### 1.3 Model
- `app/Models/PurchaseOrder.php`: xóa `order()`, xóa `order_id` khỏi `$fillable`; thêm:
```php
public function orders(): BelongsToMany
{
    return $this->belongsToMany(Order::class, 'purchase_order_orders')->withTimestamps();
}
```
- `app/Models/Order.php`: đổi `purchaseOrders()` từ `HasMany` → `BelongsToMany`:
```php
public function purchaseOrders(): BelongsToMany
{
    return $this->belongsToMany(PurchaseOrder::class, 'purchase_order_orders')->withTimestamps();
}
```

### 1.4 Controller `PurchaseOrderController`
- Validation: `order_id` → `order_ids` (`array`), `order_ids.*` (`integer|exists:orders,id`).
- `store()`: tạo PO xong gọi `$purchaseOrder->orders()->attach($data['order_ids'] ?? [])`.
- `update()`: `$purchaseOrder->orders()->sync($data['order_ids'] ?? [])`.
- `show()`: `->load('orders.customer')`; trả `linked_orders` (mảng) thay `linked_order`.
- `edit()`: trả `order_ids` = `$purchaseOrder->orders->pluck('id')`.
- Giữ nguyên query dựng list `orders` cho dropdown (không đổi).
- Sửa prefill `?order_id=` (từ nút "Thêm đơn mua" bên Sales Order) để add vào mảng thay vì set scalar.

### 1.5 Controller `Sales/OrderController`
- Không đổi logic (`->load('purchaseOrders.supplier')`, `.map()` vẫn hoạt động với `BelongsToMany`).

### 1.6 Vue
- `Purchasing/PurchaseOrders/Form.vue`: thay `<select v-model="form.order_id">` bằng multi-select đơn giản — tái dùng `RemoteSearchSelect` để chọn từng SO rồi add vào mảng `form.order_ids`, hiển thị dạng chip có nút xóa (tránh viết mới component search/dropdown).
- `Purchasing/PurchaseOrders/Show.vue`: đổi phần "Đơn hàng bán liên kết" từ 1 link sang danh sách (loop `order.linked_orders`).
- `Sales/Orders/Show.vue`: không cần đổi.

## Phase 2 — Line-item allocation (PO item ↔ SO item, quantity)

### 2.1 Migration `2026_09_25_900246_create_purchase_order_item_order_item_allocations_table.php`
Theo mẫu `stock_exit_item_lot_allocations`:
```php
Schema::create('purchase_order_item_order_item_allocations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
    $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
    $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete(); // denormalize để query nhanh
    $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
    $table->decimal('allocated_qty', 12, 3);
    $table->timestamp('voided_at')->nullable();
    $table->timestamps();
});
```

### 2.2 Ràng buộc nghiệp vụ (cần code validate, không có DB constraint đủ mạnh)
- `SUM(allocated_qty WHERE purchase_order_item_id = X AND voided_at IS NULL) <= purchase_order_items.quantity`.
- `SUM(allocated_qty WHERE order_item_id = Y AND voided_at IS NULL) <= order_items.quantity`.
- Chỉ cho phép allocate giữa PO item và Order item khi **PO đã liên kết Order đó** ở Phase 1 (order_id nằm trong `purchase_order.orders`) và **product trùng nhau** giữa 2 dòng (nghiệp vụ: không allocate khác sản phẩm).
- Sửa/xóa allocation: dùng soft-void (`voided_at`), không hard-delete (nhất quán với business rule "bút toán/liên kết đã có không xóa trực tiếp nếu chưa kiểm tra ảnh hưởng").

### 2.3 Model `PurchaseOrderItemOrderItemAllocation` + relation trên `PurchaseOrderItem`/`OrderItem`
- `PurchaseOrderItem::allocations(): HasMany`, tổng hợp `allocated_qty` qua accessor `allocatedQuantity()`.
- `OrderItem::purchaseAllocations(): HasMany` tương tự.

### 2.4 UI
- Trong `PurchaseOrders/Show.vue` (hoặc Form khi đã có nhiều dòng SO liên kết): thêm bảng phân bổ theo dòng — chọn PO item, chọn Order item (trong các SO đã liên kết), nhập số lượng, hiển thị "đã phân bổ / còn lại" cho mỗi dòng.
- Cần 1 controller mới hoặc bổ sung action trong `PurchaseOrderController` (`storeAllocation`, `destroyAllocation` — void) + route.

### 2.5 Quyết định đã chốt (2026-09-25)
1. **Đơn vị tính**: nếu `order_items.unit` có set và khác đơn vị gốc của `product` (trên PO item), **chặn allocate** (trả lỗi validate rõ ràng, không cho lưu). Không cần bảng quy đổi hệ số.
2. **Kế toán/COGS**: allocation **không** đụng vào bút toán/giá vốn/AVCO — thuần là bảng theo dõi tiến độ mua hàng theo từng đơn khách. Không cần review `AccountingService`/`AvcoService`/`StockService` cho Phase 2.

## Testing
- Feature test: PO tạo/sửa với nhiều `order_ids` → đúng bảng pivot, đúng khi update (sync loại bỏ liên kết cũ không còn chọn).
- Feature test: Sales Order Show vẫn liệt kê đúng danh sách PO sau khi đổi sang `belongsToMany`.
- Feature test: migration data — seed PO có `order_id` cũ trước, chạy migration, assert dữ liệu có mặt trong `purchase_order_orders`.
- Feature test Phase 2: allocate vượt quá `quantity` của PO item hoặc Order item → bị chặn (422); void allocation không xóa cứng.
- Chạy toàn bộ `php artisan test` liên quan Purchasing + Sales sau khi xong mỗi phase.

## Rủi ro
- Xóa cột `order_id` là thao tác khó đảo ngược (down migration không khôi phục dữ liệu) — cần chạy migration trên môi trường test/staging trước, backup DB trước khi chạy trên production.
- Phase 2 tăng đáng kể độ phức tạp UI (bảng phân bổ, validate tổng số lượng) — nên implement và test riêng Phase 1 xong, chạy ổn định rồi mới sang Phase 2, tránh 1 PR quá lớn khó review.
- Cần xác nhận 2 câu hỏi mục 2.5 trước khi code Phase 2 để tránh phá logic kế toán/giá vốn ngoài dự kiến.
