# Mini ERP — Project State

Cập nhật: 2026-06-15. File này ghi trạng thái ngắn gọn để tránh phải đọc lại toàn bộ phase-history.

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
| JournalEntry Void workflow | Hoàn thành (2026-06-13) |
| JournalEntry Edit / Draft / Unpost | Hoàn thành (2026-06-14) |
| Accounting Settings (TK cấu hình được) | Hoàn thành (2026-06-14) |
| Product item_type + revenue/inventory account UI | Hoàn thành (2026-06-14) |
| Fund Transfer (LCQ-) — luân chuyển giữa các quỹ + bút toán | Hoàn thành (2026-06-15) |
| Balance Sheet tabs — TK chưa map + kiểm tra cân đối | Hoàn thành (2026-06-15) |
| Payroll Adjustment — adjustment_amount per payroll_item | Hoàn thành (2026-06-15) |

## Migration sequence hiện tại

- Last 900xxx: `2026_06_15_900087` (payroll adjustment columns) — Next: **900088**
- Last Phase E / bank: `2026_06_05_100006` — Next (nếu cùng chủ đề bank): **100007**
- Khi tạo migration mới không liên quan bank: dùng **900088** với date hiện tại

## Known issues / risks

1. `bank_transactions.supplier_bank_account_id` và `internal_account_id` không có FK constraint DB-level. Nếu xóa SupplierBankAccount/InternalBankAccount mà đang có BT reference, sẽ có orphan. FK migration 100006 đã thêm constraint cho supplier_bank_account_id.
2. `project_members.employee_id` (đã migrate từ user_id). Nếu cần join với users, phải qua employees.user_id.
3. Không có ESLint/typecheck trong package.json scripts — chỉ có `npm run build` và `npm run dev`. `npm run lint` sẽ fail nếu gọi.
4. `@tailwindcss/vite@^4.0.0` được cài nhưng không dùng. Project dùng tailwindcss v3 qua PostCSS.
5. **H1 backfill estimated:** order_items.unit_cogs_source='backfill_estimated' nghĩa là COGS chỉ là ước tính (lấy cost_price hiện tại). Kế toán cần rà soát sản phẩm nào đã thay đổi cost_price sau khi bán.
6. **Revenue mapping:** products chưa cấu hình revenue_account_code → InvoiceService fallback về `accounting_settings.product_revenue_account` (mặc định 5111) + ghi Log::warning. Dùng trang Catalog > Sản phẩm để cấu hình từng sản phẩm.
7. **M3 proration:** Khi không có bảng chấm công đã chốt, PayrollService dùng standard_days (không prorate). Cần quyết định chính sách: có bắt buộc chốt CC trước khi tính lương không?
8. **project_materials.unit_price:** Chưa xác định là giá bán hay giá vốn — ProfitController chưa sửa phần này. Cần kế toán xác nhận.
9. **COGS AVCO:** Chưa implement tính giá vốn theo phương pháp bình quân gia quyền. Hiện tại dùng cost_price tại thời điểm xuất kho.
10. **StockExits/Form.vue:** issue_purpose + project filter + lots display chưa làm Vue (backend FIFO đã xong). Cần làm để kho có thể chọn mục đích xuất.

## Accounting — trạng thái TK hạch toán (2026-06-14)

### TK per-entity
- `Customer.receivable_account_code` (900065): per-customer TK phải thu, mặc định 1311. `getReceivableAccount()` throws nếu null.
- `Supplier.payable_account_code` (900064): per-supplier TK phải trả, mặc định 3311. `getPayableAccount()` throws nếu null.
- `Product.revenue_account_code` + `Product.inventory_account`: per-product TK doanh thu/kho. Nullable — fallback về accounting_settings.
- `ProductCategory.revenue_account_code`: per-category fallback. Nullable.

### TK hệ thống (accounting_settings)
Bảng `accounting_settings` (migration 900084) — 31 keys, cấu hình qua trang `accounting/settings`.
- Tất cả services đã dùng `AccountingSettings::get(key, default)` thay vì hardcode.
- `AccountingSettings` helper: static request-level cache; `clearCache()` gọi sau khi update.
- Thêm key mới: `INSERT INTO accounting_settings (key, value, label, ...) VALUES (...)` — không cần migration.

### TK legacy đã xử lý
- 34 legacy JEs dùng TK 331 cha → bút toán `payable_reclassification`. TK 331 net = 0 (verified VPS).
- `php artisan accounting:audit-parent-accounts` (mặc định sạch; `--include-adjusted` để xem đầy đủ).

### Trial Balance
- `?mode=adjusted` (mặc định): ẩn TK tổng hợp có số dư = 0. Tổng cộng từ tất cả TK.
- `?mode=raw`: hiển thị đầy đủ, dùng cho audit.

### PIT
- PIT constants (TT 79/2022): PERSONAL_DEDUCTION = 15,500,000 VND; DEPENDENT = 6,200,000 VND.

## Môi trường

- Local dev: `php artisan serve --host=0.0.0.0` + `npm run dev`
- DB: PostgreSQL, DB name `mini_erp_db`, host `localhost:5432`
- VPS: 103.101.161.143, deploy qua deploy.sh
