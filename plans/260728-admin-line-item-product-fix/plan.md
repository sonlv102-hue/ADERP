---
title: "Admin tool: fix wrong product link on locked line items (Order/Quotation/PurchaseOrder)"
description: "Give admins a safe in-app way to correct a wrong product_id on order_items/quotation_items/purchase_order_items when the document is in a status the normal edit form blocks — replaces the need for tinker DB surgery (real incident: DH-0027)."
status: implemented
priority: P2
effort: 2-3d
branch: feat/admin-fix-line-item-product
tags: [sales, purchasing, admin, data-integrity]
created: 2026-07-28
---

# Admin: Sửa sản phẩm sai trên dòng hàng đã khóa

## Bối cảnh
DH-0027 (Order, status=Completed) có order_item trỏ sai product_id (48 thay vì 129) do nhập liệu sai.
`OrderController::update()` chặn sửa khi status Completed/Cancelled → phải fix tay qua `php artisan
tinker` trên VPS. Cần công cụ admin-only trong app để làm việc này an toàn, có audit trail, không cần
dev đụng DB trực tiếp lần sau. Áp dụng cho 3 bảng: `order_items`, `quotation_items`,
`purchase_order_items` — KHÔNG áp dụng cho `purchase_invoice_items`/`invoice_items`.

## Precedent (bắt buộc theo đúng shape, không phát minh pattern mới)
- Service: `app/Services/StockExitDateService.php` — `DB::transaction`, throw `RuntimeException` tiếng
  Việt rõ ràng, `activity()->performedOn()->causedBy()->withProperties()->log()`.
- Controller: `StockExitController::editDate()` (~line 849) — validate `reason` + field, gọi service,
  catch `RuntimeException` → `back()->with('error', ...)`.
- Route: `role:admin` middleware trực tiếp (không dùng `can:` permission riêng).
- Test: mirror `tests/Feature/Warehouse/StockExitConfirmChainTest.php` style — TC theo số, admin OK /
  non-admin 403 / guard rail chặn đúng.
- **Khác biệt cần sửa so với precedent:** `StockExits/Show.vue` dùng modal inline tự viết (vi phạm
  `ui-style-guide.md` §8 hiện hành, ban hành SAU ngày StockExitDateService's UI được viết). 3 module mới
  PHẢI dùng `Modal.vue` chuẩn, không copy pattern modal inline cũ.

## Quyết định guard-rail đã chốt (xem chi tiết trong từng phase file)
1. **Order**: chỉ cho khi `status ∈ {Completed, Cancelled}`; chặn nếu `delivered_quantity > 0` HOẶC có
   `stock_exit_items.order_item_id` trỏ tới dòng này (dùng CẢ HAI vì FK chỉ có từ migration 900141,
   dữ liệu cũ hơn có thể thiếu FK — xem TC6/TC7 `StockExitConfirmChainTest.php`).
2. **Quotation**: chỉ cho khi `status === Cancelled`. Các status khác admin đã sửa được toàn bộ qua form
   thường (`QuotationController.php:165-172/206-212`) — không cần công cụ riêng, tránh trùng lặp.
3. **PurchaseOrder**: cho khi `status !== Draft` (Draft đã có form thường); chặn cứng nếu
   `PurchaseOrderItem::stockEntryItems()->exists()` (vì `stock_entry_items.product_id` độc lập, không
   tự đồng bộ theo PO item, và row không bị xóa khi cancel/recall — `StockService.php:210-267`).

## Phases

| # | Phase | File | Priority | Status |
|---|---|---|---|---|
| 1 | Order — OrderItemProductFixService | [phase-01](./phase-01-order-item-fix.md) | P2 | Planned |
| 2 | Quotation — QuotationItemProductFixService | [phase-02](./phase-02-quotation-item-fix.md) | P2 | Planned |
| 3 | PurchaseOrder — PurchaseOrderItemProductFixService | [phase-03](./phase-03-purchase-order-item-fix.md) | P2 | Planned |

3 phases (not fewer) — each module has a genuinely different guard condition, different DTO gap, and a
different downstream risk surface (delivery tracking vs. nothing vs. stock/AVCO/received_quantity), so
merging any two would blur the guard logic and make the diff harder to review safely.

## Cross-cutting
- No new migrations — all guard queries use existing FK indexes (`stock_exit_items.order_item_id` from
  `2026_06_20_900141`, `stock_entry_items.purchase_order_item_id` from `2026_06_13_900072`).
- No new permission — `role:admin` middleware directly, matching `StockExitDateService` precedent.
- Each phase adds `product_id` to the Show DTO where missing (Quotation, PurchaseOrder) so
  `RemoteSearchSelect` can preload the current product.
- Each Vue change uses `Modal.vue` (`Components/Shared/Modal.vue`), NOT a custom inline modal.

## Key dependencies
- Phase 1 must land before Phase 2/3 only in the sense that it validates the reusable pattern
  (service+controller+test shape) — phases are otherwise file-isolated and can run in parallel.

## Open question to confirm before coding
- Phase 1: should `OrderItemProductFixService` also re-snapshot `unit_cogs`/`unit_cogs_source`/
  `revenue_account_code` from the new product (like `OrderController::update()` does for normal edits),
  or strictly limit to `product_id`+`name` as literally requested? See phase-01 "Design Decision to
  confirm".
