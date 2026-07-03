# Mini ERP — Project State

Cập nhật: 2026-06-23. File này ghi trạng thái ngắn gọn để tránh phải đọc lại toàn bộ phase-history.

## Trạng thái hiện tại

Dự án đang ở giai đoạn **vận hành và cải tiến**. Các module core đã hoàn thành. Không còn phase lớn nào đang mở.

## Module đã hoàn thành

| Module | Trạng thái |
|---|---|
| Auth / RBAC / Users | Hoàn thành |
| CRM (Customers, Suppliers, Leads) | Hoàn thành |
| Catalog (Products, Services, PriceLists) | Hoàn thành |
| Warehouse (StockEntry/Exit/Transfer, InventoryCount) | Hoàn thành |
| AVCO — bình quân gia quyền (AvcoService, inventory_balances) | Hoàn thành (2026-06-19) |
| Sales (Quotations, Orders, Contracts, SalesReturns) | Hoàn thành |
| Purchasing (PO, PurchaseInvoice per-line, PurchaseContract, PurchaseReturn) | Hoàn thành |
| Purchase Invoice Type (9 loại, 3311 vs 3312 routing) | Hoàn thành (2026-06-15) |
| Projects (DA-, WIP/TK154, PO link, cost recognition, direct materials) | Hoàn thành |
| Project Extra Cost Transfers (kết chuyển 154 batch) | Hoàn thành (2026-06-21) |
| Project WIP Corrections | Hoàn thành (2026-06-20) |
| Support (Tickets, Warranties) | Hoàn thành |
| Accounting Core (JournalEntries, AccountCodes, Periods, Void/Edit) | Hoàn thành |
| Accounting Settings (TK cấu hình được, 31+ keys) | Hoàn thành (2026-06-14) |
| Invoices / Payments (HĐ-, per-line items) | Hoàn thành (per-line: 2026-06-20) |
| Bank Accounts / Reconciliation / Internal Transfer Report | Hoàn thành |
| Bank Transaction enhancements (counterpart, tx_type, internal_status) | Hoàn thành |
| Prepaid Expenses (CPT-) | Hoàn thành |
| Fixed Assets / Depreciation / CCDC (phase J) | Hoàn thành (2026-06-15) |
| CCDC — Công cụ dụng cụ (small_tools, 7 tables, SmallToolService) | Hoàn thành (2026-06-18) |
| Payroll / PIT / Insurance / Attendance (CC-) | Hoàn thành |
| Payroll chi lương qua Fund (CashVoucher PC-) | Hoàn thành (2026-06-16) |
| CashVouchers / Funds / Fund Transfers (LCQ-) | Hoàn thành |
| Supplier Advance / TK 331UT (SupplierAdvanceService) | Hoàn thành (2026-06-19) |
| Customer Advance / TK 131UT (CustomerAdvanceService) | Hoàn thành (2026-06-19) |
| Supplier Prepayment Offset (đối trừ trả trước NCC) | Hoàn thành (2026-06-17) |
| AR/AP Cash Voucher integration (PT-/PC- tự động khi thanh toán) | Hoàn thành (2026-06-17) |
| AR/AP Opening Balances | Hoàn thành |
| Personal Finance (Shareholders TV-, PersonalLoan PVay-, PersonalExpense PCH-) | Hoàn thành (2026-06-15) |
| Reports (B01a, B02-DNN, B03-DNN, Trial Balance, Ledger, AR/AP, S01/S02a/S03a) | Hoàn thành (B02/B03: 2026-06-21) |
| Documents / DocumentTypes / AuditLog UI / Notifications | Hoàn thành |
| Commissions | Hoàn thành |
| Admin System Health | Hoàn thành (2026-06-19) |
| RemoteSearchSelect (dropdown lớn, 8 endpoints) | Hoàn thành (2026-06-18, đợt 2: 2026-06-22) |
| Mobile Responsive (118 tables, 39 form grids, 83 page headers, Modal) | Hoàn thành (2026-06-19) |
| Period Filter cho Internal Transfer Report | Hoàn thành (2026-06-22) |
| Admin sửa ngày xuất kho (StockExit confirmed) — StockExitDateService | Hoàn thành (2026-07-01) |
| B02-DNN Income Statement lọc theo Tháng/Quý/Năm/Tùy chọn + so sánh kỳ | Hoàn thành (2026-07-01) |
| B03-DNN Cash Flow Statement lọc theo Tháng/Quý/Năm/Tùy chọn + so sánh kỳ | Hoàn thành (2026-07-01) |
| HRM Employee Export/Import (Excel/PDF/Print + import preview/confirm) | Hoàn thành (2026-07-01) |
| CCDC/CPTT: Nhập số dư đầu kỳ + Tạm dừng/Tiếp tục phân bổ | Hoàn thành (2026-07-02) |

## Migration sequence hiện tại

- **Last 900xxx:** `2026_07_02_900219` (số dư đầu kỳ + tạm dừng/tiếp tục phân bổ CCDC/CPTT)
- **Next:** `2026_07_02_900220`
- Last Phase E / bank: `2026_06_05_100006` — Next (cùng chủ đề bank): `100007`

## TK hệ thống (accounting_settings)

Bảng `accounting_settings` (migration 900084) — 31+ keys, cấu hình qua trang `accounting/settings`.
Tất cả services dùng `AccountingSettings::get('key', 'fallback')` — không hardcode TK.

### TK per-entity
- `Customer.receivable_account_code` (mặc định 1311). `getReceivableAccount()` throws nếu null.
- `Supplier.payable_account_code` (mặc định 3311). `getPayableAccount()` throws nếu null.
- `Product.revenue_account_code` + `inventory_account` — nullable, fallback về accounting_settings.

### TK đặc biệt
- Ứng trước NCC: `supplier_opening_advances.account_code` = **'331UT'** (không phải '3311')
- Ứng trước KH: `customer_opening_advances.account_code` = **'131UT'**
- TK 331 cha `is_detail=true` — cho phép dùng trực tiếp (migration 900045)
- `BankAccount.account_code` bắt buộc là TK chi tiết (is_detail=true)

## Known issues / risks

1. `bank_transactions.internal_account_id` không có DB-level FK constraint — cẩn thận khi xóa InternalBankAccount.
2. `project_members.employee_id` (từ migration 900039). Join với users phải qua `employees.user_id`.
3. Không có ESLint/typecheck scripts — `npm run lint` sẽ fail. Chỉ có `build` và `dev`.
4. Stock entries NK-* Confirmed trước 2026-06-09 có Cr 331 thiếu VAT — cần reverse + re-confirm nếu cần sổ sách chính xác.
5. `AccountingService::tryPost()` silently swallows exceptions (return null) — nếu JE = null sau confirm, kiểm tra FK account_codes.
6. B03-DNN classify dominant counterpart có thể sai với JE phức tạp nhiều TK đối ứng.
7. **H1 backfill estimated:** `order_items.unit_cogs_source='backfill_estimated'` nghĩa là COGS chỉ là ước tính. Kế toán cần rà soát.
8. Non-project stock exit **BLOCKS** nếu không có `inventory_balances` (AVCO chưa init). Project exit vẫn dùng FIFO.
9. `resolvePeriod()`/`resolveComparison()`/`previousCalendarPeriod()`/`fileSlug()` duplicate y hệt giữa `IncomeStatementController` và `CashFlowStatementController` (lọc kỳ báo cáo tháng/quý/năm/tùy chọn). Nếu sửa lỗi ở 1 nơi (VD: date-math cho `previous_period`/`same_period_last_year`) phải sửa cả 2 file — chưa tách thành service dùng chung.
10. `Tests\Feature\Accounting\JournalEntryVoidTest::trial_balance_unaffected_after_void_pair` **fail sẵn trên `master`** (xác nhận bằng `git stash` + chạy lại — không do thay đổi CCDC/CPTT gây ra), lỗi `getAllBalancesAsOf('2026-06-30')` trả về 0 thay vì -1.000.000. Cần điều tra riêng — có thể do phụ thuộc ngày cố định trong test khi "hôm nay" đã qua 2026-07-01.
11. `PrepaidExpenses/Form.vue` (form tạo CPTT thường, không phải opening balance) có default `expense_account: '642'` không khớp option nào trong dropdown (chỉ có 6421/6422/627/635) — TK 642 là TK tổng hợp, `AccountingService::validateLines()` sẽ reject nếu user submit mà không đổi select → lỗi 500. Bug có sẵn, phát hiện khi review code liên quan; chưa sửa vì ngoài phạm vi task CCDC/CPTT opening-balance (form Số dư đầu kỳ CPTT mới đã tự sửa default đúng '6422').

## Accounting — JE FSM

`draft → posted → reversed → voided` (terminal); `draft → hard delete`.
Kỳ khóa → block void/unpost. Bút toán đã posted không sửa trực tiếp — dùng `AccountingService::unpost()` trước.

## Môi trường

- Local dev: `php artisan serve --host=0.0.0.0` + `npm run dev`
- DB: PostgreSQL, DB name `mini_erp_db`, host `localhost:5432`
- VPS: 103.101.161.143, Docker (5 containers), deploy qua `sync-vps.ps1`
- Tests: 560+ tests (all pass, last verified 2026-06-23)
