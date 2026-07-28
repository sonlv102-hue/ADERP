# Phase 01 — Order: fix wrong product_id on a locked order_item

## Context Links
- Real incident: DH-0027, order_item product_id 48 → 129, fixed by tinker (motivating case).
- Precedent: `app/Services/StockExitDateService.php`, `StockExitController::editDate()` (~line 849),
  route `routes/web.php:263`, `resources/js/Pages/Warehouse/StockExits/Show.vue:39-134,281-334`.
- Guard evidence: `app/Http/Controllers/Sales/OrderController.php:408-413` (update() lock),
  `database/migrations/2026_06_20_900141_add_order_item_id_to_stock_exit_items_and_purchase_orders_junction.php:12-18`
  (order_item_id FK on stock_exit_items, added 2026-06-20),
  `tests/Feature/Warehouse/StockExitConfirmChainTest.php:359-438` (TC6/TC7 — proves legacy exits can
  deliver without order_item_id populated, fallback-by-product_id),
  `database/migrations/2026_05_23_900008_add_delivery_tracking.php` (delivered_quantity column).
- DTO reference: `OrderController::show()` items map already includes `product_id`
  (`app/Http/Controllers/Sales/OrderController.php:305` block) — no DTO change needed here.

## Overview
- **Priority:** P2
- **Status:** Planned
- Admin-only fix for a mis-linked product on an `order_items` row when the order is Completed or
  Cancelled (the only statuses `OrderController::update()` blocks — other statuses already editable
  normally, don't duplicate).

## Key Insights
- `order_items` has NO `subtotal` column (computed via `lineTotal()`); no DB risk from that angle.
- `order_items.name` IS a real snapshot column (`database/migrations/2026_05_21_200004_create_order_items_table.php:16`)
  — must be updated to the new product's name, else Show/PDF display drifts from `product_id`.
- `delivered_quantity` is the authoritative "has this line already moved stock" signal, always present
  regardless of migration date; the `stock_exit_items.order_item_id` FK is a secondary, more specific
  check but proven unreliable alone for pre-2026-06-20 data (see TC6/TC7 above). Both checks required.
- `service_id` must be nulled when swapping to a product (mutually exclusive per schema).

## Design Decision (CONFIRMED by user 2026-07-28)
`order_items` also carries `unit_cogs`, `unit_cogs_source`, `revenue_account_code` — snapshot fields
computed from the product at the time the normal edit form runs (`OrderController::update()` lines
~440-450). **Confirmed: the fix service also recomputes these three from the new product**, using the
same formula as `update()`, so a product swap doesn't leave COGS/revenue account stale.

## Requirements
- Admin-only (`role:admin` middleware, not a permission code — mirrors precedent).
- Mandatory `reason` textarea (required, string, max 255).
- Only `product_id` (+ `name`, + optionally COGS/revenue fields per decision above) changes —
  `quantity`, `unit_price`, `discount_percent`, `discount_amount`, `delivered_quantity`, `vat_rate`
  untouched.
- Guard: `order.status ∈ {Completed, Cancelled}` else throw (other statuses use normal edit form).
- Guard: block if `delivered_quantity > 0` OR `StockExitItem::where('order_item_id', $item->id)->exists()`.
- Activity log entry on the parent `Order` with old/new product_id, old/new name, reason, actor.

## Architecture
- New `app/Services/OrderItemProductFixService.php` — single method, `DB::transaction`, row-level
  `lockForUpdate()` on both `Order` and `OrderItem`.
- Controller action added to `OrderController` (matches precedent's "controller owns action" style —
  `editDate()` lives directly on `StockExitController`).
- Vue: new modal block added to `resources/js/Pages/Sales/Orders/Show.vue`'s items table row.

## Related Code Files

**Create:**
- `app/Services/OrderItemProductFixService.php`
- `tests/Feature/Sales/OrderItemProductFixTest.php`

**Modify:**
- `app/Http/Controllers/Sales/OrderController.php` — add `fixLineItemProduct(Request, Order, OrderItem)`.
- `routes/web.php` — add route inside `sales.` group (near line 318).
- `resources/js/Pages/Sales/Orders/Show.vue` — add "Sửa sản phẩm" icon-button (admin-only, only on
  Completed/Cancelled rows) + `Modal.vue` instance near the items `<tr>` loop (line 92).

## Implementation Steps
1. `OrderItemProductFixService::fixProductLink(OrderItem $item, int $newProductId, string $reason, User $actor): array`:
   - `DB::transaction`, reload `$item`/`$item->order` with `lockForUpdate()`.
   - Guard 1: `if (!in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) throw RuntimeException('Đơn hàng đang ở trạng thái có thể sửa qua form chỉnh sửa thông thường.')`.
   - Guard 2: `if ((float)$item->delivered_quantity > 0) throw RuntimeException('Dòng hàng đã có số lượng giao — không thể đổi sản phẩm.')`.
   - Guard 3: `if (StockExitItem::where('order_item_id', $item->id)->exists()) throw RuntimeException('Dòng hàng đã liên kết phiếu xuất kho — không thể đổi sản phẩm.')`.
   - Guard 4: `if ($newProductId === $item->product_id) throw RuntimeException('Sản phẩm mới trùng sản phẩm hiện tại.')`.
   - Load `$newProduct = Product::with('category:id,revenue_account_code')->findOrFail($newProductId)`.
   - Snapshot old values for the log.
   - Update `product_id`, `service_id = null`, `name = $newProduct->name` (+ COGS/revenue fields per
     Design Decision, using the same formula as `OrderController::update()`).
   - `activity()->performedOn($order)->causedBy($actor)->withProperties([...])->log("Sửa sản phẩm dòng hàng #{$item->id} trên đơn {$order->code}: {$reason}")`.
   - Return old/new snapshot array.
2. Controller: validate `reason` (required|string|max:255) + `product_id` (required|exists:products,id);
   `abort_if($orderItem->order_id !== $order->id, 404)`; call service; catch `RuntimeException` →
   `back()->with('error', ...)`; success → `back()->with('success', 'Đã sửa sản phẩm.')`.
3. Route: `Route::post('orders/{order}/items/{orderItem}/fix-product', [OrderController::class, 'fixLineItemProduct'])->name('orders.items.fix-product')->middleware('role:admin');`
4. Vue: `isAdmin` computed (reuse pattern from `StockExits/Show.vue:281`); button
   `v-if="isAdmin && ['completed','cancelled'].includes(order.status)"` per row; `Modal.vue` with
   `RemoteSearchSelect` (`search.products`) + `reason` textarea; `router.post(route('sales.orders.items.fix-product', [order.id, item.id]), form, { preserveScroll: true, onSuccess, onError, onFinish })`.
5. Tests mirroring the Required Tests section below.

## Todo List
- [ ] `OrderItemProductFixService`
- [ ] Controller action + route
- [ ] Vue modal (Modal.vue) + button
- [ ] Feature tests
- [ ] `php artisan test` green
- [ ] `npm run build` green

## Success Criteria
- Admin can fix product on a Completed/Cancelled order_item with no delivery/exit link, sees activity
  log entry, quantity/price untouched.
- Attempt on Processing/Pending order → blocked with clear message pointing to normal edit form.
- Attempt on a delivered/exit-linked item → blocked regardless of order status.
- Non-admin → 403.

## Required Tests (`tests/Feature/Sales/OrderItemProductFixTest.php`)
- TC1: admin fixes product on Completed order, no delivery → success, `name`/`product_id` updated,
  `quantity`/`unit_price` unchanged, activity log row created.
- TC2: admin fixes product on Cancelled order → success.
- TC3: blocked when order.status = Processing (must use normal edit form instead).
- TC4: blocked when `delivered_quantity > 0`.
- TC5: blocked when a `stock_exit_items.order_item_id` links to this row (even if delivered_quantity=0,
  e.g. legacy data edge case).
- TC6: non-admin → 403, data unchanged.
- TC7: missing `reason` → validation error.

## Risk Assessment
- If Design Decision (COGS/revenue re-snapshot) is skipped, downstream reports may show COGS from the
  wrong (old) product for this line — must be documented as known limitation if declined.
- `orderItem->order_id !== order->id` mismatch must 404, not silently operate on wrong parent.

## Security Considerations
- `role:admin` middleware (route-level) — matches precedent, no new permission needed.
- `reason` required and logged for accountability (matches `StockExitDateService` pattern).

## Next Steps
- After Phase 1 ships and pattern is validated, proceed to Phase 2 (Quotation) — structurally similar
  but much lighter guard.
