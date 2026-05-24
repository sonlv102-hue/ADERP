# Phase 03 — Sales Return (Trả hàng bán)

## Context Links
- `app/Models/Order.php`, `OrderItem.php` (has `delivered_quantity` for partial delivery).
- `app/Services/StockService.php` — entry confirm pattern.
- `app/Services/OrderService.php` — `syncDelivery()` pattern for cross-module sync.

## Overview
- **Priority:** P2
- **Status:** Completed
- Customer returns goods from a confirmed Order. On confirm: stock comes back IN to warehouse. Code prefix `TH-`.

## Key Insights
- Return tied to existing `Order` (FK orders restrictOnDelete). Items must reference order's products.
- Returnable qty = `delivered_quantity - already_returned_qty` per product on order. Enforced server-side.
- On confirm: ONE stock movement IN per item (no need to create a StockEntry record — we already have the polymorphic source pattern). Use `source_type = SalesReturn::class`.
- Decision: do NOT auto-create StockEntry to keep it simple. Movement is the source of truth.

## Requirements
- Enum `SalesReturnStatus`: Draft, Confirmed, Cancelled.
- Form: pick Order → auto-load deliverable items → user picks qty per row.
- Permissions: `sales-returns.view`, `.create`, `.confirm`, `.cancel`.
- Optional: rollback `order_items.delivered_quantity` on return confirm (so order can be re-shipped or shows accurate net). V1: subtract from `delivered_quantity` to keep `total_delivered = net_delivered`.

## Architecture
- Migration pair: `2026_05_23_900012`, `2026_05_23_900013`.
- Controller: `app/Http/Controllers/Sales/SalesReturnController.php`.
- Service: `app/Services/SalesReturnService.php` — `confirm()`, `cancel()`.

## Related Code Files

**Create:**
- `app/Enums/SalesReturnStatus.php`
- `database/migrations/2026_05_23_900012_create_sales_returns_table.php`
- `database/migrations/2026_05_23_900013_create_sales_return_items_table.php`
- `app/Models/SalesReturn.php`
- `app/Models/SalesReturnItem.php`
- `app/Services/SalesReturnService.php`
- `app/Http/Controllers/Sales/SalesReturnController.php`
- `resources/js/Pages/Sales/SalesReturns/Index.vue`
- `resources/js/Pages/Sales/SalesReturns/Form.vue`
- `resources/js/Pages/Sales/SalesReturns/Show.vue`

**Modify:**
- `routes/web.php` — `sales.` prefix.
- `resources/js/Components/Layout/Sidebar.vue` — under "Bán hàng" NavGroup.
- `database/seeders/RolePermissionSeeder.php`.

## Implementation Steps
1. Enum `SalesReturnStatus` with `label()`/`color()` (Draft=gray, Confirmed=green, Cancelled=red).
2. Migration `sales_returns`: `id`, `code` unique, `order_id` FK orders restrictOnDelete, `warehouse_id` FK warehouses restrictOnDelete, `return_date` date, `reason` text nullable, `status` default 'draft', `created_by` FK users, timestamps. Index on `order_id`.
3. Migration `sales_return_items`: `id`, `sales_return_id` FK cascadeOnDelete, `order_item_id` FK order_items restrictOnDelete, `product_id` FK restrictOnDelete, `quantity` decimal(15,2), `unit_price` decimal(15,2) nullable (snapshot from order_item for refund calc), timestamps.
4. Model `SalesReturn`: `$fillable`, casts (`status` enum, `return_date` date), `generateCode()` prefix `TH-`. Relations: `order()`, `warehouse()`, `items()`, `creator()`.
5. Model `SalesReturnItem`: `$fillable`, casts (`quantity`, `unit_price` => 'decimal:2'). Relations: `salesReturn()`, `orderItem()`, `product()`.
6. Service `SalesReturnService::confirm(SalesReturn $r)`:
   - Guard status===Draft.
   - Re-validate per item: `qty <= (orderItem.delivered_quantity - sumOfPriorReturnsForThisOrderItem)`. Throw with product name + max returnable.
   - DB::transaction:
     - For each item: `StockMovement::create(type='in', quantity=+qty, warehouse=$r->warehouse_id, source_type=SalesReturn::class, source_id=$r->id, notes="Trả hàng bán {$r->code}")`.
     - Update `$orderItem->delivered_quantity -= $qty`.
     - After all items processed, re-evaluate `$order->status`: if `delivered_quantity < ordered` for any item → drop from `Completed`/`PartialDelivered` accordingly (reuse OrderService::syncDelivery logic — call shared helper or inline simple recompute).
     - `$r->update(['status' => Confirmed])`.
7. Service `SalesReturnService::cancel(SalesReturn $r)`:
   - If Draft → status=Cancelled.
   - If Confirmed → reversal movements (type='out', quantity=-qty) + restore `delivered_quantity += qty` + status=Cancelled.
8. Controller `SalesReturnController`:
   - `index`: paginate with `with(['order.customer','warehouse','creator'])`.
   - `create`: list confirmed/completed orders (status in [Processing, Completed, PartialDelivered]) + warehouses + nextCode.
   - `getOrderItems(Order $order)` AJAX/Inertia partial: returns deliverable items with `max_returnable` computed (delivered − prior returns).
   - `store`: validate items + qty caps server-side; create header+items in transaction.
   - `show`, `confirm`, `cancel` per pattern.
9. Vue `Index.vue`: code, order.code, customer, return_date, status badge, total qty.
10. Vue `Form.vue`: 
    - Step 1: select Order (autocomplete by code/customer).
    - Step 2: load order items via `router.reload({ only: ['orderItems'] })` or eager pass — each row shows `delivered`, `prior_returned`, `max_returnable`, input qty (max-bound).
    - Submit via `form.post(route('sales.sales-returns.store'))`.
11. Vue `Show.vue`: header + items + Confirm/Cancel buttons.
12. Routes:
    ```php
    Route::resource('sales-returns', SalesReturnController::class)->only(['index','create','store','show']);
    Route::post('sales-returns/{salesReturn}/confirm', [SalesReturnController::class,'confirm'])->name('sales-returns.confirm');
    Route::post('sales-returns/{salesReturn}/cancel', [SalesReturnController::class,'cancel'])->name('sales-returns.cancel');
    Route::get('sales-returns/order/{order}/items', [SalesReturnController::class,'orderItems'])->name('sales-returns.order-items');
    ```
13. Sidebar inside "Bán hàng" NavGroup: `<NavItem v-if="can('sales-returns.view')" :href="route('sales.sales-returns.index')" icon="reply" sub>Trả hàng bán</NavItem>`.
14. Seeder: add 4 perms; admin (all), sales (view/create/cancel), warehouse (confirm), director (view).

## Todo List
- [ ] Enum `SalesReturnStatus`
- [ ] Migrations (pair)
- [ ] Models `SalesReturn`, `SalesReturnItem`
- [ ] Service `SalesReturnService`
- [ ] Controller + 4 routes
- [ ] Vue Index/Form/Show
- [ ] Sidebar + Seeder
- [ ] Smoke test: create order → deliver → return partial → verify stock + delivered_qty

## Success Criteria
- Cannot return more than `delivered - prior_returns` per item.
- Confirmed return increases stock at chosen warehouse.
- `order.status` syncs back from Completed → PartialDelivered when items returned.
- Cancel of confirmed return restores stock and delivered_quantity correctly.

## Risk Assessment
- Order status sync edge cases: order with `delivered_quantity = 0` after return → status should revert to Processing/Confirmed. Test with single-item order returned 100%.
- Returns of products NOT on original order rejected at validation.

## Security Considerations
- `can:sales-returns.*` middleware.
- `order_item_id` validated to belong to the chosen `order_id`.

## Next Steps
- Future: credit-note PDF generation tied to return.
