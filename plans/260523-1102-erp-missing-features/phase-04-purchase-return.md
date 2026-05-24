# Phase 04 — Purchase Return (Trả hàng mua)

## Context Links
- `app/Models/PurchaseOrder.php`, `PurchaseOrderItem.php`.
- `app/Models/StockEntry.php` — track which entries belong to PO.
- Mirror Phase 03 structure.

## Overview
- **Priority:** P2
- **Status:** Completed
- Return goods to supplier from a confirmed PurchaseOrder. On confirm: stock goes OUT of warehouse. Code prefix `THM-`.

## Key Insights
- Returnable qty per PO item = `total_received - prior_returns`. `total_received` = sum of confirmed StockEntryItems linked to the PO grouped by product.
- On confirm: ONE stock movement OUT per item. `source_type = PurchaseReturn::class`.
- Pre-confirm guard: source warehouse stock must be ≥ qty (re-using StockService current-stock check pattern).
- Serial-tracked products: V1 treats as bulk (do not change ProductSerial state). Document as limitation.

## Requirements
- Enum `PurchaseReturnStatus`: Draft, Confirmed, Cancelled.
- Form: pick PurchaseOrder → load received items with max-returnable.
- Permissions: `purchase-returns.view`, `.create`, `.confirm`, `.cancel`.

## Architecture
- Migrations: `2026_05_23_900014`, `2026_05_23_900015`.
- Controller: `app/Http/Controllers/Purchasing/PurchaseReturnController.php`.
- Service: `app/Services/PurchaseReturnService.php`.

## Related Code Files

**Create:**
- `app/Enums/PurchaseReturnStatus.php`
- `database/migrations/2026_05_23_900014_create_purchase_returns_table.php`
- `database/migrations/2026_05_23_900015_create_purchase_return_items_table.php`
- `app/Models/PurchaseReturn.php`
- `app/Models/PurchaseReturnItem.php`
- `app/Services/PurchaseReturnService.php`
- `app/Http/Controllers/Purchasing/PurchaseReturnController.php`
- `resources/js/Pages/Purchasing/PurchaseReturns/Index.vue`
- `resources/js/Pages/Purchasing/PurchaseReturns/Form.vue`
- `resources/js/Pages/Purchasing/PurchaseReturns/Show.vue`

**Modify:**
- `routes/web.php` — `purchasing.` prefix.
- `resources/js/Components/Layout/Sidebar.vue` — under "Mua hàng" NavGroup.
- `database/seeders/RolePermissionSeeder.php`.

## Implementation Steps
1. Enum `PurchaseReturnStatus` (Draft/Confirmed/Cancelled) with `label()`+`color()` (gray/green/red).
2. Migration `purchase_returns`: `id`, `code` unique, `purchase_order_id` FK restrictOnDelete, `supplier_id` FK suppliers restrictOnDelete (snapshot for quick query), `warehouse_id` FK warehouses restrictOnDelete, `return_date` date, `reason` text nullable, `status` default 'draft', `created_by` FK users, timestamps. Index `purchase_order_id`.
3. Migration `purchase_return_items`: `id`, `purchase_return_id` FK cascadeOnDelete, `purchase_order_item_id` FK restrictOnDelete, `product_id` FK restrictOnDelete, `quantity` decimal(15,2), `unit_price` decimal(15,2) nullable (snapshot incl. VAT — matches PO convention), timestamps.
4. Model `PurchaseReturn`: `$fillable`, casts (`status`, `return_date`), `generateCode()` prefix `THM-`. Relations: `purchaseOrder()`, `supplier()`, `warehouse()`, `items()`, `creator()`.
5. Model `PurchaseReturnItem`: relations `purchaseReturn()`, `purchaseOrderItem()`, `product()`.
6. Service `PurchaseReturnService::confirm(PurchaseReturn $r)`:
   - Guard status===Draft.
   - For each item, compute `total_received = SUM(stock_entry_items.quantity)` where stock_entry → confirmed and `purchase_order_id = $r->purchase_order_id` and `product_id = item.product_id`.
   - Compute `prior_returns = SUM(purchase_return_items.quantity)` for confirmed prior returns same PO + product.
   - `max_returnable = total_received - prior_returns`. Throw if `item.quantity > max_returnable`.
   - Check current stock at `$r->warehouse_id` ≥ item.quantity. Throw if insufficient.
   - DB::transaction:
     - For each item: `StockMovement::create(type='out', quantity=-qty, warehouse=$r->warehouse_id, source_type=PurchaseReturn::class, source_id=$r->id, notes="Trả NCC {$r->code}")`.
     - `$r->update(['status' => Confirmed])`.
7. Service `PurchaseReturnService::cancel(PurchaseReturn $r)`:
   - Draft → just Cancelled.
   - Confirmed → reversal movements (type='in', quantity=+qty) + status=Cancelled.
8. Controller `PurchaseReturnController`:
   - `index`: paginate with `with(['purchaseOrder','supplier','warehouse','creator'])`.
   - `create`: list POs with status in [Received, PartialReceived] + warehouses + nextCode.
   - `poItems(PurchaseOrder $po)` GET: returns items + computed `total_received`/`prior_returned`/`max_returnable` per item.
   - `store`: validate caps; create header+items in transaction.
   - `show`, `confirm`, `cancel` per pattern.
9. Vue `Index.vue`: code, PO code, supplier, return_date, status badge, total qty.
10. Vue `Form.vue`: PO selector → load via Inertia partial → items table with qty input bounded by `max_returnable`.
11. Vue `Show.vue`: header + items + Confirm/Cancel.
12. Routes:
    ```php
    Route::resource('purchase-returns', PurchaseReturnController::class)->only(['index','create','store','show']);
    Route::post('purchase-returns/{purchaseReturn}/confirm', [PurchaseReturnController::class,'confirm'])->name('purchase-returns.confirm');
    Route::post('purchase-returns/{purchaseReturn}/cancel', [PurchaseReturnController::class,'cancel'])->name('purchase-returns.cancel');
    Route::get('purchase-returns/po/{purchaseOrder}/items', [PurchaseReturnController::class,'poItems'])->name('purchase-returns.po-items');
    ```
13. Sidebar inside "Mua hàng" NavGroup: `<NavItem v-if="can('purchase-returns.view')" :href="route('purchasing.purchase-returns.index')" icon="reply" sub>Trả hàng mua</NavItem>`.
14. Seeder: add 4 perms; admin (all), warehouse (all), director (view), accounting (view).

## Todo List
- [ ] Enum `PurchaseReturnStatus`
- [ ] Migrations (pair)
- [ ] Models
- [ ] Service `PurchaseReturnService`
- [ ] Controller + 4 routes
- [ ] Vue Index/Form/Show
- [ ] Sidebar + Seeder
- [ ] Smoke test: PO → receive → return partial → verify stock OUT + max-returnable enforcement

## Success Criteria
- Cannot return > `received - prior_returns`.
- Cannot return when current stock insufficient.
- Confirmed return decreases warehouse stock.
- Cancel restores stock.

## Risk Assessment
- PO with multiple receipts (partial deliveries): `total_received` must aggregate across all confirmed StockEntries — query must sum all StockEntryItems via confirmed StockEntries.
- Serialized products: V1 ignores serial state; document and plan V2.

## Security Considerations
- `can:purchase-returns.*` middleware.
- `purchase_order_item_id` validated to belong to chosen `purchase_order_id`.

## Next Steps
- Future: tie purchase return to credit-note on PurchaseInvoice.
