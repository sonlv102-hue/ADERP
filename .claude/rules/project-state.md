# Mini ERP — Project State

Cập nhật: 2026-07-29. File này ghi trạng thái ngắn gọn để tránh phải đọc lại toàn bộ phase-history.

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
| CCDC: Xóa hồ sơ (destroy) — chặn nếu có receipt/issue/transfer/disposal/posted allocation/JE, bắt buộc lý do + activity log | Hoàn thành (2026-07-03) |
| CCDC: Export Excel/PDF + Import từ file mẫu Excel (Danh sách CCDC) — import luôn tạo draft, không tự ghi bút toán | Hoàn thành (2026-07-03) |
| Bảng chấm công: Export Excel (cột ngày động theo số ngày trong tháng + tổng hợp Công/NghỉHL/NghỉKL/OT/Tổng) | Hoàn thành (2026-07-03) |
| Phiếu kế toán thủ công: chọn Dự án + Nhóm chi phí per-line (`journal_entry_lines.project_id/cost_group`), tự tạo `project_wip_entries` khi post dòng Nợ154, bắt buộc validate, soft-cancel WIP khi đảo/hủy, chống trùng WIP; command `journal-entries:audit-project-dimensions` + `journal-entries:repair-legacy-project-wip` (dry-run) | Hoàn thành (2026-07-15) |
| Admin tự sửa product_id sai trên dòng hàng đã khóa (Order/Quotation/PurchaseOrder) — thay thế tinker DB surgery; `Order{,Quotation,PurchaseOrder}ItemProductFixService`, route `role:admin`, Modal.vue UI, activity log. Guard rail riêng từng loại (xem `phase-history.md` Services & FSM) | Hoàn thành (2026-07-28) |
| Sổ chi tiết Nhập-Xuất-Tồn (Mẫu S10-DN) trong menu Kho — báo cáo chi tiết theo từng giao dịch (khác báo cáo tổng hợp `InventoryReportService` ở menu Báo cáo); `InventoryTransactionReportService/Controller/Export`, route `reports.inventory_transactions{,.export}` (permission `reports.view` có sẵn), menu item `warehouse.report.transactions` | Hoàn thành (2026-07-29) |
| **So sánh báo giá NCC** (Mua hàng) — module LOCAL, chưa migrate/deploy production. 6 bảng `purchase_quote_*` (mig `2026_08_28_900233..238`), `PurchaseQuoteComparison{,Matrix}Service` + `PurchaseQuoteImport/Selection`, `/purchasing/quote-comparisons`, perm `purchases.quote_comparisons.*`, seeder `PurchaseQuoteComparisonSeeder`. KHÔNG side-effect kế toán/kho/PO. Chi tiết → memory `project-quote-comparison-module` | Chờ nghiệm thu (2026-08-28) |
| **Nhân viên thôi việc** — `employees.termination_*` (mig `2026_09_07_900239`), tái dùng `EmployeeStatus` (không thêm `employment_status`). `EmployeeTerminationService` (terminate/cancelTermination + prune payroll draft kỳ sau + cảnh báo kỳ khóa). `Employee::scope{Working,ActiveOn,EmployedDuring}`. `PayrollService::createPayroll`/`syncFromEmployees` + `AttendanceController::store` + `SearchController::employees` chuyển sang lọc theo `termination_date` thay vì `whereIn('status',...)`. Perm `hr.employees.terminate{,_cancel}` (seeder `EmployeeTerminationSeeder` + RolePermissionSeeder). Form.vue khóa status resigned; Show.vue nút Thôi việc/Hủy + panel đỏ; báo cáo biến động `admin.employees.headcount` | Hoàn thành (2026-09-07), đã deploy production |
| **Dòng tiền tài khoản công ty — Phase 1/4 + Hardening** (Tài chính → Báo cáo) — mig `2026_09_14_900240..242`: bảng `cash_flow_categories` (29 danh mục seed sẵn) + 10 cột mới trên `bank_transactions` (`cash_flow_category_id/project_id/contract_type+id/party_type+id+name/responsible_user_id/cash_flow_note/paired_transaction_id`) + 2 index perf (`bank_tx_account_date_idx`, `bank_tx_party_idx`) — **hoàn toàn tách biệt** với `matched_party_type/id`/`matched_document_type/id`/`tx_type`/`internal_status`/`match_status` vốn do `BankTransactionMatchingService`/`BankTransactionAllocationService`/`InternalTransferReportController` sở hữu, chỉ ghi qua `CashFlowClassificationService::update()`. Service này hardening thêm: whitelist field + validate party_id/contract_id tồn tại ĐÚNG bảng theo party_type/contract_type (map cứng, không resolve class từ client input), category phải `is_active` + đúng `direction` với debit/credit (trừ khi giữ nguyên category cũ), concurrency check qua `expected_updated_at` (throw `ValidationException` nếu bị người khác sửa trước), party_name server-side lấy từ model (chống spoofing). `CashFlowInternalTransferMatchingService` gợi ý cặp (không tự confirm), đánh dấu `ambiguous=true` kèm toàn bộ `candidates` khi >1 giao dịch khớp cùng số tiền (không tự chọn bừa), `confirmPair()` guard cả 2 phía phải có `internal_account_id` + khác bank_account. `BankTransaction::scopeExcludingInternalTransfers()` loại khỏi dòng tiền thuần TOÀN CÔNG TY theo **2 điều kiện độc lập**: đã cặp đôi (`paired_transaction_id`) HOẶC đã phân loại category=chuyển tiền nội bộ dù chưa cặp được (fix quan trọng — trước hardening chỉ loại theo pairing, khiến giao dịch nội bộ chưa cặp bị tính nhầm thành thu/chi thật); bug NULL đã gặp và fix: `whereNotIn` loại luôn cả `cash_flow_category_id IS NULL` (SQL 3-value logic) — phải OR thêm `whereNull`. `CompanyCashFlowReportService` thêm filter `reconcile_status` (SQL replicate chính xác `reconcileStatus()`), field `balance_source=calculated` trong summary (chưa có nguồn số dư xác nhận từ ngân hàng). Route thêm `reports.company-cashflow.unpair`; Index.vue thêm filter dự án/reconcile_status, nút "Gợi ý cặp chuyển khoản nội bộ" (`PairSuggestionsModal.vue` — trước hardening 2 endpoint pairSuggestions/confirmPair tồn tại nhưng KHÔNG có UI nào gọi tới), nút "Hủy cặp"; `ClassifyModal.vue` gửi kèm `expected_updated_at`. Route `reports.company-cashflow.*`, perm `reports.bank_cashflow.{view,transactions.view,reconcile,edit,balance.view}`, menu `accounting.reports.bank_cashflow`. **CHƯA làm** (Phase 2+): biểu đồ, trang drill-down riêng theo dự án/đối tượng, Excel đa sheet, import CSV, sổ tạm ứng NV dạng ledger, liên kết đơn hàng/đợt thanh toán. Test: `CompanyCashFlowTest`(9) + `CompanyCashFlowValidationTest`(13) + `CompanyCashFlowInternalTransferEdgeCaseTest`(5) + `CompanyCashFlowFilterAndAuthTest`(11) + `CompanyCashFlowPerformanceTest`(2) + `CompanyCashFlowContractResponsibleTest`(7) + `CompanyCashFlowPartialUpdateTest`(5) = 52 test module này, full suite 967 passed/0 failed (2026-09-14).
  **Final acceptance round (2026-09-14, cùng ngày):** thêm UI Hợp đồng (`search.contracts` mới, chọn contract/purchase_contract) + Người phụ trách (`search.users` mới) trên `ClassifyModal.vue` (trước đó backend đã hỗ trợ nhưng UI thiếu); `CashFlowReconcileStatus::NeedsReview` được gán nghiệp vụ thật (Hướng A): category=chuyển tiền nội bộ + CHƯA cặp đôi → NeedsReview, ưu tiên cao hơn Completed (đổi label thành "Chuyển nội bộ – chưa có đối ứng", dùng chung cho cảnh báo UI); `applyReconcileStatusFilter()` cập nhật khớp SQL, cross-check test mở rộng qua mọi state.
  **2 bug thật phát hiện qua browser E2E (Playwright, dùng `playwright-core` sẵn có + chromium cache local, KHÔNG cần thêm dependency) + Postgres thật (không phải SQLite)**:
  (1) `ClassifyModal.vue` không pre-fill `party_id`/`project_id` khi mở lại giao dịch đã phân loại → sửa field khác (vd note) sẽ NULL hoá mất — đã fix (DTO trả thêm 2 field, modal pre-fill đúng), test `CompanyCashFlowPartialUpdateTest`.
  (2) **NGHIÊM TRỌNG**: Index.vue luôn gửi TOÀN BỘ filterForm kể cả field chưa chọn (`value=""`) — trên Postgres, `WHERE cash_flow_category_id = ''` (cột bigint) throw `QueryException 22P02 invalid input syntax for type bigint`, khiến trang 500 bất cứ khi nào bấm "Lọc" mà không chọn đủ mọi dropdown. SQLite KHÔNG phát hiện được (ép kiểu lỏng lẻo) — chỉ lộ ra khi test qua Postgres thật. Fix tại `CompanyCashFlowController::filters()`: `array_filter($filters, fn($v) => $v !== '')` loại field rỗng, giữ `null` thật (sentinel "chưa xác định"). Regression test dùng `DB::listen`/`getQueryLog()` assert không binding nào là `''` (driver-agnostic, tự phát hiện lại nếu tái phát dù chạy trên SQLite).
  Postgres sanity check (30k dòng, rollback sau test, không đụng dữ liệu thật): index `bank_tx_account_date_idx` được dùng khi lọc 1 account + date range hẹp (EXPLAIN ANALYZE: 521 buffer reads → 113, 1.05ms → 0.32ms so với không có index); PG18 tận dụng `bank_tx_cash_flow_idx` cho cả lọc project_id (không phải cột đầu index) nhờ skip-scan.
  **Browser E2E**: chạy được thật (login/RBAC/render/filter/RemoteSearchSelect category+party+project+contract+responsible/save/persist qua Postgres đều xác nhận hoạt động, dữ liệu lưu đúng khi query trực tiếp DB), nhưng KHÔNG ổn định 100% — 1 bước (mở modal ngay sau khi bấm "Lọc" trong CÙNG 1 script chạy liên tục) thỉnh thoảng không tìm thấy đúng dòng dù dữ liệu backend/DTO đều đúng (nghi ngờ race Inertia SPA, chưa xác định được root cause chắc chắn do không có công cụ xem trực quan). Dữ liệu test đã dọn sạch khỏi `mini_erp_db`. **Chưa coi là browser acceptance PASS chính thức** — cần người vận hành xác nhận thủ công (xem checklist trong báo cáo cuối).
  **Phase 1.1 — Xuất Excel** (2026-09-15): endpoint `reports.company-cashflow.export`, perm `reports.bank_cashflow.export` (seeder gia tăng `CashFlowReportPermissionSeeder`, không đụng `RolePermissionSeeder` trên production). Workbook 6 sheet (`01_Tong_quan`..`06_Chua_doi_soat`), tái dùng 100% business logic `CompanyCashFlowReportService` (4 method mới: `transactionsForExport/contractLabelsByTransactionId/partiesBreakdown/categoryBreakdownForExport`), chống Excel formula injection (`SanitizesFormulaInjection`), `WithStrictNullComparison` (bug thư viện phát hiện qua audit: Maatwebsite mặc định coi 0 như null khi ghi cell). Test: `CompanyCashFlowExportTest` (15 case) + full suite 999 passed/0 failed (SQLite) + 114 passed/0 failed (PostgreSQL 16 targeted). **Quyết định RBAC (pre-deploy audit 2026-09-15):** `reports.bank_cashflow.export` đi theo đúng convention export sẵn có của hệ thống (role `director` tự động nhận mọi permission `action=export` qua catch-all rule trong `RolePermissionSeeder` — đã áp dụng từ trước cho 9 permission export khác). Không tạo ngoại lệ RBAC riêng cho module này. Lưu ý: rule catch-all này chỉ áp dụng khi chạy `RolePermissionSeeder` (fresh install/`db:seed` đầy đủ) — trên production hiện tại (đã deploy commit `aad5d52`), permission được cấp qua `CashFlowReportPermissionSeeder` (chỉ admin/super_admin/accounting, không đụng `director`). | **PHASE 1.1 EXPORT READY FOR PRODUCTION** (2026-09-15), LOCAL — chưa deploy production, chờ approval deploy riêng |

## Migration sequence hiện tại

- **Last 900xxx:** `2026_09_14_900242` (2 index perf cho dòng tiền công ty). Trước đó: `2026_09_14_900241` (cột dòng tiền công ty trên bank_transactions), `2026_09_14_900240` (bảng cash_flow_categories), `2026_09_07_900239` (termination fields trên employees), `2026_08_28_900233..900238` (6 bảng So sánh báo giá NCC), `2026_08_14_900232` (payroll_items override tracking).
- **Next:** `2026_09_14_900243`
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
10. ~~`Tests\Feature\Accounting\JournalEntryVoidTest::trial_balance_unaffected_after_void_pair` fail sẵn trên `master`~~ — **đã fix** trong commit `ac1365e` (2026-07-09, "...fix void test"). Xác nhận lại 2026-07-14: `php artisan test` 797 passed / 0 failed. Không còn là known issue.
11. `PrepaidExpenses/Form.vue` (form tạo CPTT thường, không phải opening balance) có default `expense_account: '642'` không khớp option nào trong dropdown (chỉ có 6421/6422/627/635) — TK 642 là TK tổng hợp, `AccountingService::validateLines()` sẽ reject nếu user submit mà không đổi select → lỗi 500. Bug có sẵn, phát hiện khi review code liên quan; chưa sửa vì ngoài phạm vi task CCDC/CPTT opening-balance (form Số dư đầu kỳ CPTT mới đã tự sửa default đúng '6422').
12. **6 JE cũ id 999-1004 (G2, tổng 182.565.000đ, "kết chuyển lương kỹ thuật" Dr154/Cr627)** thiếu `project_id`/`cost_group` — phát hiện qua `journal-entries:audit-project-dimensions`. **Chưa tự sửa** — chỉ Medium confidence hướng về DA-0001 (dự án in_progress duy nhất), không có bằng chứng trực tiếp. Dùng `journal-entries:repair-legacy-project-wip --je=999,1000,1001,1002,1003,1004 --project=<mã> --cost-group=labor --dry-run` để xem trước, cần kế toán xác nhận đúng dự án trước khi thêm `--apply`.
13. **`OrderItem::$fillable` thiếu field (đã fix 2026-07-28):** trước fix, `unit`, `unit_cogs`, `unit_cogs_source`, `revenue_account_code` bị mass-assignment silently drop trên MỌI order tạo/sửa qua `OrderController`. Đã sửa `$fillable`. **Dữ liệu lịch sử (order_items tạo trước 2026-07-28) có thể có `unit_cogs`/`unit_cogs_source`/`revenue_account_code` = NULL — CHƯA backfill**, theo quyết định của user (tự xử lý sau, có thể qua chính công cụ admin fix-product ở mục 57 phía trên vì service đó re-snapshot COGS khi đổi product). Không tự ý chạy backfill nếu chưa được yêu cầu.
14. **`deploy.sh` KHÔNG chạy `db:seed`** (chỉ `migrate --force`) — menu (`menu_items`) là DB-driven và chỉ được seed qua `RolePermissionSeeder`. Bất kỳ feature mới nào thêm `MenuItem::create()` vào seeder sẽ **không tự xuất hiện trên VPS sau deploy** cho tới khi seeder được chạy thủ công. Phát hiện lần đầu 2026-07-29: menu "Nhập-Xuất-Tồn chi tiết" (Kho) mất tích trên VPS dù route/controller đã hoạt động — do `menu_items` production thiếu đúng 1 row. Đã fix bằng cách insert thủ công đúng 1 row qua tinker (an toàn, không đụng permissions/roles) thay vì chạy lại cả seeder — vì `RolePermissionSeeder` có `MenuItem::truncate()` + `role->permissions()->sync()` theo danh sách cứng, có thể ghi đè các thay đổi quyền thủ công trên production nếu có. **Khi thêm menu item mới trong tương lai**: sau khi deploy, phải kiểm tra `MenuItem::where('key', '...')->exists()` trên VPS và insert thủ công nếu thiếu (hoặc xác nhận rõ với user trước khi chạy lại `db:seed --class=RolePermissionSeeder`).

## Accounting — JE FSM

`draft → posted → reversed → voided` (terminal); `draft → hard delete`.
Kỳ khóa → block void/unpost. Bút toán đã posted không sửa trực tiếp — dùng `AccountingService::unpost()` trước.

## Môi trường

- Local dev: `php artisan serve --host=0.0.0.0` + `npm run dev`
- DB: PostgreSQL, DB name `mini_erp_db`, host `localhost:5432`
- VPS: 103.101.161.143, Docker (5 containers), deploy qua `sync-vps.ps1`
- Tests: 838+ tests (all pass, last verified 2026-07-29)
