# Phase 02 — Quotation: fix wrong product_id on a Cancelled quotation_item

## Context Links
- Guard evidence: `app/Http/Controllers/Sales/QuotationController.php:165-172` (edit) and `:206-212`
  (update) — admin can already edit ANY status except Cancelled via the normal form. Cancelled is the
  ONLY status this new tool needs to cover.
- `app/Models/Quotation.php:87-90` (`orders(): HasMany`) — confirms `orders.quotation_id` FK exists.
- `app/Services/QuotationService.php:68-78` (`convertToOrder`) — order_items are created as independent
  snapshots at conversion time, no live FK back to quotation_items → safe to edit a converted quotation's
  line without affecting any existing Order.
- DTO gap: `QuotationController::show()` items map (`app/Http/Controllers/Sales/QuotationController.php:134-144`)
  does NOT currently include `product_id` — must add.

## Overview
- **Priority:** P2
- **Status:** Planned
- Admin-only fix for `quotation_items.product_id` when `quotation.status === Cancelled` (the one status
  the existing admin-capable edit form still blocks). All other statuses already have full admin edit
  via the standard Form — do not duplicate.

## Key Insights
- Quotations don't post journal entries or move stock — lowest-risk of the 3 modules.
- `quotation_items` has a real `name` snapshot column (`create_quotation_items` migration, line ~16) —
  must update alongside `product_id`.
- No `unit_cogs`/`revenue_account_code` equivalent exists on `quotation_items` — nothing else to
  re-snapshot (simpler than Order).
- Editing a Cancelled quotation whose `orders()` relation is non-empty should NOT be blocked — just
  show a non-blocking informational note in the UI (no live FK, no accounting impact).

## Requirements
- Admin-only (`role:admin` middleware).
- Mandatory `reason` textarea.
- Only `product_id` + `name` change.
- Guard: `quotation.status === QuotationStatus::Cancelled` else throw (other statuses → use normal form).
- Informational (non-blocking) warning shown in UI if `quotation->orders()->exists()`.
- Activity log on the `Quotation`.

## Architecture
- New `app/Services/QuotationItemProductFixService.php` — same shape as Phase 1's service, much shorter
  guard section.
- Controller action on `QuotationController`.
- Vue: modal in `resources/js/Pages/Sales/Quotations/Show.vue` items table (line 66).

## Related Code Files

**Create:**
- `app/Services/QuotationItemProductFixService.php`
- `tests/Feature/Sales/QuotationItemProductFixTest.php`

**Modify:**
- `app/Http/Controllers/Sales/QuotationController.php` — add `fixLineItemProduct(...)`; add `product_id`
  to `show()` items DTO (line ~137).
- `routes/web.php` — add route inside `sales.` group (near line 298).
- `resources/js/Pages/Sales/Quotations/Show.vue` — button + `Modal.vue`.

## Implementation Steps
1. `QuotationItemProductFixService::fixProductLink(QuotationItem $item, int $newProductId, string $reason, User $actor): array`:
   - `DB::transaction`, `lockForUpdate()` on `$item`/`$item->quotation`.
   - Guard: `if ($quotation->status !== QuotationStatus::Cancelled) throw RuntimeException('Chỉ áp dụng cho báo giá đã hủy — các trạng thái khác đã sửa được qua form chỉnh sửa thông thường (dành cho admin).')`.
   - Guard: new product_id must differ from current.
   - Update `product_id`, `service_id = null`, `item_type = 'product'`, `name = $newProduct->name`.
   - `activity()->performedOn($quotation)->causedBy($actor)->withProperties([...])->log(...)`.
2. Controller: validate `reason` + `product_id`; scope check `quotationItem->quotation_id === quotation->id`;
   catch `RuntimeException` → `back()->with('error', ...)`.
3. Add `'product_id' => $item->product_id,` to the `show()` items map.
4. Route: `Route::post('quotations/{quotation}/items/{quotationItem}/fix-product', ...)->name('quotations.items.fix-product')->middleware('role:admin');`
5. Vue: button `v-if="isAdmin && quotation.status === 'cancelled'"`; if `quotation.orders?.length`, show
   an inline `text-xs text-slate-500` note "Báo giá này đã được chuyển thành đơn hàng — sửa dòng này
   không ảnh hưởng đơn hàng đã tạo." inside the modal (informational only, not a blocker).
6. Tests mirroring Phase 1's structure.

## Todo List
- [ ] `QuotationItemProductFixService`
- [ ] Controller action + route + DTO `product_id` addition
- [ ] Vue modal (Modal.vue) + informational note
- [ ] Feature tests
- [ ] `php artisan test` green
- [ ] `npm run build` green

## Success Criteria
- Admin fixes product on a Cancelled quotation → success, activity logged.
- Attempt on Draft/Sent/Approved/Rejected/Expired → blocked, error points to normal edit form.
- Non-admin → 403.
- Converted quotation (has orders) → still editable, informational note shown, no data corruption in
  the already-created Order.

## Required Tests (`tests/Feature/Sales/QuotationItemProductFixTest.php`)
- TC1: admin fixes product on Cancelled quotation → success, name/product_id updated, activity logged.
- TC2: blocked on Draft/Sent/Approved (use normal form instead) — assert one representative status.
- TC3: non-admin → 403, data unchanged.
- TC4: missing reason → validation error.
- TC5: quotation already converted to Order (orders()->exists()) → edit still succeeds, Order's
  order_items remain unchanged (proves no live FK impact).

## Risk Assessment
- Low — no stock/accounting side effects. Main risk is scope creep (don't extend to non-Cancelled
  statuses; that's already covered by the existing admin edit form).

## Security Considerations
- `role:admin` middleware; `reason` required + logged.

## Next Steps
- Phase 3 (PurchaseOrder) carries the highest risk — do not start until Phase 1's pattern is reviewed.
