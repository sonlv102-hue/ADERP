# Phase 02 — Stock Transfer (Chuyển kho)

## Context Links
- `app/Services/StockService.php` — pattern for confirm/cancel + StockMovement creation.
- `app/Models/StockMovement.php` — polymorphic `source_type`/`source_id`.
- `app/Models/StockEntry.php` — model pattern.

## Overview
- **Priority:** P2
- **Status:** Completed
- Move stock from warehouse A to warehouse B. On confirm: two `StockMovement` rows in one transaction (out from source, in to destination).

## Key Insights
- Tồn kho = SUM(stock_movements.quantity) per (product, warehouse). Negative movement on source decreases its stock, positive on destination increases.
- Use `StockTransfer::class` as `source_type` for both movements so we can trace both legs.
- Stock validation: `currentStock >= qty` for each line on source warehouse BEFORE confirm.
- No serial handling in V1 — only non-serialized products (or treat serials as bulk for transfer). Document this limitation.

## Requirements
- New enum `StockTransferStatus`: Draft, Confirmed, Cancelled.
- Code prefix `CK-0001`.
- Permissions: `stock-transfers.view`, `.create`, `.confirm`, `.cancel`.
- Disallow same source = destination warehouse (validation).

## Architecture
- Migration pair: `2026_05_23_900010_create_stock_transfers_table.php`, `2026_05_23_900011_create_stock_transfer_items_table.php`.
- Service: `app/Services/StockTransferService.php` — `confirm(StockTransfer $t)`, `cancel(StockTransfer $t)`.
- Controller: `app/Http/Controllers/Warehouse/StockTransferController.php` injects service.

## Related Code Files

**Create:**
- `app/Enums/StockTransferStatus.php`
- `database/migrations/2026_05_23_900010_create_stock_transfers_table.php`
- `database/migrations/2026_05_23_900011_create_stock_transfer_items_table.php`
- `app/Models/StockTransfer.php`
- `app/Models/StockTransferItem.php`
- `app/Services/StockTransferService.php`
- `app/Http/Controllers/Warehouse/StockTransferController.php`
- `resources/js/Pages/Warehouse/StockTransfers/Index.vue`
- `resources/js/Pages/Warehouse/StockTransfers/Form.vue`
- `resources/js/Pages/Warehouse/StockTransfers/Show.vue`

**Modify:**
- `routes/web.php` — under `warehouse.` prefix.
- `resources/js/Components/Layout/Sidebar.vue` — `<NavItem>` under "Kho hàng" NavGroup.
- `database/seeders/RolePermissionSeeder.php` — 4 permissions; assign to `admin`, `warehouse`, `director`.

## Implementation Steps
1. Enum `StockTransferStatus` with `label()` + `color()` (mirror StockEntryStatus). Cases: Draft=gray, Confirmed=green, Cancelled=red.
2. Migration `stock_transfers`: `id`, `code` unique, `from_warehouse_id` FK warehouses restrictOnDelete, `to_warehouse_id` FK warehouses restrictOnDelete, `transfer_date` date, `status` string default 'draft', `notes` text nullable, `created_by` FK users, timestamps.
3. Migration `stock_transfer_items`: `id`, `stock_transfer_id` FK cascadeOnDelete, `product_id` FK restrictOnDelete, `quantity` decimal(15,2), timestamps. Index on (stock_transfer_id, product_id).
4. Model `StockTransfer`:
   - `$fillable`, `casts: ['status' => StockTransferStatus::class, 'transfer_date' => 'date']`.
   - `generateCode()` prefix `CK-`.
   - Relations: `fromWarehouse()`, `toWarehouse()` BelongsTo Warehouse, `items()` HasMany, `creator()`.
5. Model `StockTransferItem`: `$fillable = ['stock_transfer_id', 'product_id', 'quantity']`. Relations: `transfer()`, `product()`.
6. Service `StockTransferService::confirm(StockTransfer $t)`:
   - Guard: status === Draft.
   - Guard: `from_warehouse_id !== to_warehouse_id`.
   - Pre-validate stock on source for each item: `SUM(stock_movements WHERE product+warehouse) >= item.quantity`. Throw RuntimeException listing the product if insufficient.
   - `DB::transaction`:
     - For each item: `StockMovement::create` (type='out', quantity=-qty, warehouse=from, source_type=StockTransfer::class, source_id=$t->id, notes="Chuyển kho phiếu {$t->code} → {$to->name}").
     - For each item: `StockMovement::create` (type='in', quantity=+qty, warehouse=to, source_type=StockTransfer::class, source_id=$t->id, notes="Nhận từ chuyển kho phiếu {$t->code}").
     - `$t->update(['status' => Confirmed])`.
7. Service `StockTransferService::cancel(StockTransfer $t)`:
   - If Draft → just set status=Cancelled.
   - If Confirmed → DB::transaction: insert reversal movements (in to source, out from destination) + status=Cancelled. (Mirror StockService::cancelEntry pattern.)
8. Controller `StockTransferController`:
   - Constructor: `__construct(private StockTransferService $svc)`.
   - `index`: paginate with eager `with(['fromWarehouse', 'toWarehouse', 'creator'])` + `->through(fn)`.
   - `create`: pass warehouses + products list + nextCode.
   - `store`: validate `from_warehouse_id`, `to_warehouse_id` (different), `transfer_date`, `items` array with `product_id`+`quantity > 0`. Create transfer + items in transaction. Redirect to show.
   - `show`: render with items.
   - `confirm`: try/catch RuntimeException → flash error.
   - `cancel`: same.
9. Vue `Index.vue`: table with code, from/to, transfer_date, items count, status badge, actions.
10. Vue `Form.vue`: warehouse selects (filter `to` !== `from` client-side), repeating item rows with product picker + quantity, live stock-on-hand hint (optional, query backend).
11. Vue `Show.vue`: header info + items table + Confirm/Cancel buttons gated by status + permission.
12. Routes:
    ```php
    Route::resource('stock-transfers', StockTransferController::class)->only(['index','create','store','show']);
    Route::post('stock-transfers/{stockTransfer}/confirm', [StockTransferController::class,'confirm'])->name('stock-transfers.confirm');
    Route::post('stock-transfers/{stockTransfer}/cancel', [StockTransferController::class,'cancel'])->name('stock-transfers.cancel');
    ```
13. Sidebar entry inside "Kho hàng" NavGroup: `<NavItem v-if="can('stock-transfers.view')" :href="route('warehouse.stock-transfers.index')" icon="switch-horizontal" sub>Chuyển kho</NavItem>`.
14. Seeder: add `stock-transfers.view`, `.create`, `.confirm`, `.cancel`. Roles: admin (all), warehouse (all), director (view).

## Todo List
- [x] Enum `StockTransferStatus`
- [x] Migration `stock_transfers`
- [x] Migration `stock_transfer_items`
- [x] Migration add `stock_transfer_item_id` to `product_serials`
- [x] Model `StockTransfer`
- [x] Model `StockTransferItem`
- [x] Model `ProductSerial` — added `stock_transfer_item_id` fillable + relation
- [x] Service `StockTransferService`
- [x] Controller `StockTransferController`
- [x] Vue Index/Form/Show
- [x] Routes + Sidebar + Seeder
- [ ] Smoke test: create draft → confirm → verify movements on both warehouses → cancel → verify reversal

## Success Criteria
- Confirmed transfer creates exactly 2N movements (N items × 2 legs).
- Source warehouse stock decreases; destination increases by same qty.
- Insufficient stock throws clear error with product name.
- `from === to` rejected at validation layer.

## Risk Assessment
- Race condition on stock check (read-then-write). Mitigation: wrap in DB::transaction (single connection); if MySQL InnoDB used, accept eventual consistency for V1. Document for future locking.
- Serialized products: V1 transfers them in bulk (no serial movement). Document; address in V2.

## Security Considerations
- `can:stock-transfers.*` middleware.
- `from_warehouse_id` and `to_warehouse_id` validated `exists:warehouses,id` and `different:from_warehouse_id`.

## Next Steps
- V2: serial-aware transfer (move ProductSerial.warehouse_id).
