# Mini ERP — Project State

Cập nhật: 2026-06-07. File này ghi trạng thái ngắn gọn để tránh phải đọc lại toàn bộ phase-history.

## Trạng thái hiện tại

Dự án đang ở giai đoạn **vận hành và cải tiến**, các module core đã hoàn thành. Không còn phase lớn nào đang mở.

## Module đã hoàn thành (tóm tắt)

| Module | Trạng thái |
|---|---|
| Auth / RBAC / Users | Hoàn thành |
| CRM (Customers, Suppliers, Leads) | Hoàn thành |
| Catalog (Products, Services, PriceLists) | Hoàn thành |
| Warehouse (StockEntry/Exit/Transfer, InventoryCount) | Hoàn thành |
| Sales (Quotations, Orders, Contracts, SalesReturns) | Hoàn thành |
| Purchasing (PO, PurchaseInvoice, PurchaseContract, PurchaseReturn) | Hoàn thành |
| Projects (DA-, WIP/TK154, PO link, recognize cost) | Hoàn thành |
| Support (Tickets, Warranties) | Hoàn thành |
| Accounting Core (JournalEntries, AccountCodes, Periods) | Hoàn thành |
| Invoices / Payments (HĐ-) | Hoàn thành |
| Bank Accounts / Reconciliation | Hoàn thành |
| Bank Transaction enhancements (counterpart, tx_type, internal_status) | Hoàn thành (Phase E, 2026-06-05) |
| Supplier Bank Accounts | Hoàn thành (Phase E, 2026-06-05) |
| Internal Bank Accounts / Internal Transfer Report | Hoàn thành (Phase E, 2026-06-05) |
| Prepaid Expenses (CPT-) | Hoàn thành |
| Fixed Assets / Depreciation | Hoàn thành |
| Payroll / PIT / Insurance | Hoàn thành |
| Attendance (CC-) | Hoàn thành (Phase D, 2026-06-03) |
| CashVouchers / Funds | Hoàn thành |
| Reports (Balance Sheet, P&L, Trial Balance, Ledger, AR/AP, etc.) | Hoàn thành |
| Documents / DocumentTypes | Hoàn thành |
| Commissions | Hoàn thành |
| Notifications | Hoàn thành |
| AuditLog UI | Hoàn thành |
| Backup | Hoàn thành |
| COGS snapshot per order_item (H1) | Hoàn thành (2026-06-07) |
| Revenue account mapping per invoice item (M1) | Hoàn thành (2026-06-07) |
| KPCĐ employer contribution / TK 3382 (M2) | Hoàn thành (2026-06-07) |
| Attendance snapshot vào payroll item / proration (M3) | Hoàn thành (2026-06-07) |
| AccountingPostingJob retry (M4) | Hoàn thành (2026-06-07) |
| Balance Sheet TK 421 retained earnings (M5) | Hoàn thành (2026-06-07) |

## Migration sequence hiện tại

- Last 900xxx: `2026_06_07_900056` — Next: **900057**
- Last Phase E / bank: `2026_06_05_100006` — Next (nếu cùng chủ đề bank): **100007**
- Khi tạo migration mới không liên quan bank: dùng **900057** với date hiện tại

## Known issues / risks

1. `bank_transactions.supplier_bank_account_id` và `internal_account_id` không có FK constraint DB-level. Nếu xóa SupplierBankAccount/InternalBankAccount mà đang có BT reference, sẽ có orphan. FK migration 100006 đã thêm constraint cho supplier_bank_account_id.
2. `project_members.employee_id` (đã migrate từ user_id). Nếu cần join với users, phải qua employees.user_id.
3. Không có ESLint/typecheck trong package.json scripts — chỉ có `npm run build` và `npm run dev`. `npm run lint` sẽ fail nếu gọi.
4. `@tailwindcss/vite@^4.0.0` được cài nhưng không dùng. Project dùng tailwindcss v3 qua PostCSS.
5. **H1 backfill estimated:** order_items.unit_cogs_source='backfill_estimated' nghĩa là COGS chỉ là ước tính (lấy cost_price hiện tại). Kế toán cần rà soát sản phẩm nào đã thay đổi cost_price sau khi bán.
6. **M1 revenue mapping pending:** products có item_type='software'|'other' chưa được cấu hình revenue_account_code. InvoiceService fallback 5111 và ghi Log::warning. Kế toán cần cấu hình từng sản phẩm trong admin.
7. **M3 proration:** Khi không có bảng chấm công đã chốt, PayrollService dùng standard_days (không prorate). Cần quyết định chính sách: có bắt buộc chốt CC trước khi tính lương không?
8. **project_materials.unit_price:** Chưa xác định là giá bán hay giá vốn — ProfitController chưa sửa phần này. Cần kế toán xác nhận.

## Môi trường

- Local dev: `php artisan serve --host=0.0.0.0` + `npm run dev`
- DB: PostgreSQL, DB name `mini_erp_db`, host `localhost:5432`
- VPS: 103.101.161.143, deploy qua deploy.sh
