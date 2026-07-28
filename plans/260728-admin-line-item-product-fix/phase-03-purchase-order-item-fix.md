# Phase 03 — PurchaseOrder: fix wrong product_id on a locked purchase_order_item

**Highest risk phase — inventory/AVCO impact. Do not implement without re-confirming guard #2 against
live data before shipping (per CLAUDE.md's explicit warning on kế toán/kho business logic).**

## Context Links
- Guard evidence (status): `app/Http/Controllers/Purchasing/PurchaseOrderController.php:322-326` (edit)
  and `:373-376` (update) — normal form only allows edits when `status === Draft`.
- Guard evidence (received/stock link): `app/Services/StockService.php:38-95` (`confirmEntry()` —
  increments `PurchaseOrderItem.received_quantity`, creates `StockMovement`/`ProjectInventoryLot`/AVCO
  rows keyed off `StockEntryItem.product_id`, NOT `purchase_order_item.product_id`);
  `app/Models/StockEntryItem.php:11-14` (own independent `product_id` column — no sync mechanism back
  to the PO item); `app/Models/PurchaseOrderItem.php:33-36` (`stockEntryItems(): HasMany` relation);
  `StockService.php:210-267` (`cancelEntry()`/`recallEntry()` — decrement `received_quantity` back but
  NEVER delete `stock_entry_items` rows, meaning `received_quantity` can read 0 while historical
  `stock_entry_items` referencing the old product still exist).
- Confirmed NOT a risk: `purchase_invoice_items` has no FK to `purchase_order_id` or `product_id` at all
  (`app/Models/PurchaseInvoiceItem.php`) — invoices are free-form lines, header-linked only via
  `purchase_invoices.purchase_order_id` (nullable). No guard needed on that axis.
- Schema fact: `purchase_order_items` has NO `name` column
  (`database/migrations/2026_05_21_600002_create_purchase_order_items_table.php`) — only `product_id`
  is ever wrong/fixable; display always resolves live via `item.product` relation.
- DTO gap: `PurchaseOrderController::show()` items map (`app/Http/Controllers/Purchasing/PurchaseOrderController.php:284-293`)
  does NOT include `product_id` — must add.

## Overview
- **Priority:** P2 (but treat implementation with P0 care — inventory/accounting integrity)
- **Status:** Planned
- Admin-only fix for `purchase_order_items.product_id` when PO is Sent/PartialReceived/Received/
  Cancelled (anything but Draft, which already has the normal edit form), AND no `stock_entry_items`
  have ever referenced this PO line (live or historical/cancelled).

## Key Insights
- `received_quantity > 0` alone is NOT a sufficient guard — it can be decremented back to 0 by
  `cancelEntry()`/`recallEntry()` while orphaned `stock_entry_items` rows (with the OLD product_id)
  still physically exist, still referencing `purchase_order_item_id`. Use
  `stockEntryItems()->exists()` (superset check) instead.
- `ProjectInventoryLot.purchase_order_item_id` (migration `2026_06_13_900076_create_project_inventory_lots_table.php:24`)
  is populated in the SAME transaction as `stock_entry_items` creation (`StockService.php:139-150`) —
  so it's fully covered by the `stockEntryItems()->exists()` check (no separate query needed).
- Because `purchase_order_items` has no `name`/`description` field, this service is the simplest of
  the 3 in terms of what changes — but the guard logic is the most involved.

## Requirements
- Admin-only (`role:admin` middleware).
- Mandatory `reason` textarea.
- Only `product_id` changes.
- Guard: `purchaseOrder.status !== PurchaseOrderStatus::Draft` (Draft → use normal form).
- Guard: `poItem->stockEntryItems()->exists()` → hard block, no override.
- Activity log on the `PurchaseOrder`.

## Architecture
- New `app/Services/PurchaseOrderItemProductFixService.php`.
- Controller action on `PurchaseOrderController`.
- Vue: modal in `resources/js/Pages/Purchasing/PurchaseOrders/Show.vue` items table (line 151).

## Related Code Files

**Create:**
- `app/Services/PurchaseOrderItemProductFixService.php`
- `tests/Feature/Purchasing/PurchaseOrderItemProductFixTest.php`

**Modify:**
- `app/Http/Controllers/Purchasing/PurchaseOrderController.php` — add `fixLineItemProduct(...)`; add
  `product_id` to `show()` items DTO (line ~289).
- `routes/web.php` — add route inside `purchasing.` group (near line 787).
- `resources/js/Pages/Purchasing/PurchaseOrders/Show.vue` — button + `Modal.vue`.

## Implementation Steps
1. `PurchaseOrderItemProductFixService::fixProductLink(PurchaseOrderItem $item, int $newProductId, string $reason, User $actor): array`:
   - `DB::transaction`, `lockForUpdate()` on `$item`/`$item->purchaseOrder`.
   - Guard: `if ($po->status === PurchaseOrderStatus::Draft) throw RuntimeException('Đơn mua ở trạng thái nháp — sửa qua form chỉnh sửa thông thường.')`.
   - Guard: `if ($item->stockEntryItems()->exists()) throw RuntimeException('Dòng hàng đã có phiếu nhập kho tham chiếu (kể cả đã hủy) — không thể đổi sản phẩm. Vui lòng tạo dòng mới hoặc liên hệ kế toán để xử lý thủ công.')`.
   - Guard: new product_id must differ from current.
   - Update `product_id` only.
   - `activity()->performedOn($po)->causedBy($actor)->withProperties(['item_id' => $item->id, 'old_product_id' => ..., 'new_product_id' => ..., 'reason' => $reason])->log(...)`.
2. Controller: validate `reason` + `product_id`; scope check `poItem->purchase_order_id === $purchaseOrder->id`;
   catch `RuntimeException` → `back()->with('error', ...)`.
3. Add `'product_id' => $item->product_id,` to the `show()` items map.
4. Route: `Route::post('purchase-orders/{purchaseOrder}/items/{purchaseOrderItem}/fix-product', ...)->name('purchase-orders.items.fix-product')->middleware('role:admin');`
5. Vue: button `v-if="isAdmin && order.status !== 'draft'"` per row; `Modal.vue` with product search +
   reason; on server-side block, surface the exact RuntimeException message (already precise) via
   `onError`.
6. Tests — pay special attention to the "cancelled entry, received_quantity back to 0, but
   stock_entry_items row still exists" case (TC5 below) since it's the subtlest guard.

## Todo List
- [ ] `PurchaseOrderItemProductFixService`
- [ ] Controller action + route + DTO `product_id` addition
- [ ] Vue modal (Modal.vue)
- [ ] Feature tests (incl. cancelled-entry edge case)
- [ ] `php artisan test` green
- [ ] `npm run build` green
- [ ] Manual review against a real Sent/PartialReceived PO with no stock entries before merge

## Success Criteria
- Admin fixes product on a Sent PO item with zero stock entries ever created → success.
- Blocked on Draft (use normal form).
- Blocked when a CONFIRMED stock entry references this item (received_quantity > 0).
- Blocked when a CANCELLED/RECALLED stock entry historically referenced this item, even though
  `received_quantity` is back to 0 (the subtle case this plan specifically guards against).
- Non-admin → 403.

## Required Tests (`tests/Feature/Purchasing/PurchaseOrderItemProductFixTest.php`)
- TC1: admin fixes product on Sent PO with no stock entries → success, `product_id` updated, activity
  logged, `quantity`/`unit_price`/`received_quantity` unchanged.
- TC2: blocked on Draft PO (normal form applies).
- TC3: blocked when a confirmed `StockEntry` has a `StockEntryItem` referencing this `purchase_order_item_id`
  (`received_quantity > 0`).
- TC4: blocked when PO status is Received/PartialReceived and the entry is still confirmed.
- TC5 (critical): create a `StockEntry`, confirm it against this PO item, then cancel the `StockEntry`
  via `StockService::cancelEntry()` (received_quantity returns to 0) → fix attempt still blocked because
  the (now-cancelled) `stock_entry_items` row still exists and references `purchase_order_item_id`.
- TC6: non-admin → 403.
- TC7: missing reason → validation error.

## Risk Assessment
- **Highest-risk phase in this plan.** Any gap in the `stockEntryItems()->exists()` guard could let an
  admin silently desynchronize the PO's claimed product from what AVCO/`inventory_balances`/
  `project_inventory_lots` actually recorded as received — a double-booking/wrong-COGS risk explicitly
  called out in CLAUDE.md. Do not relax this guard without re-tracing `AvcoService`/`ProjectInventoryLot`
  consumers first.
- If in practice this guard makes the tool unusable for most real POs (since most locked/non-Draft POs
  have already received at least partially), that's the correct, conservative outcome — the tool exists
  for the narrow case of a wrong product caught BEFORE any physical receiving (e.g., Sent status, typo
  caught before goods arrive), not for retroactively rewriting inventory history.

## Security Considerations
- `role:admin` middleware; `reason` required + logged.
- No override/force flag — if blocked, the correct path is a manual accounting-reviewed correction
  (stock adjustment + PO line correction together), out of scope for this tool.

## Next Steps
- After all 3 phases ship, consider whether `docs-manager` should add a short note to an inventory doc
  referencing when NOT to use this tool (received PO lines) so accounting/warehouse staff know its
  limits.
