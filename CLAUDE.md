# Mini ERP — Index

Hệ thống quản lý nội bộ cho công ty kinh doanh và thi công giải pháp IT.  
Tài liệu: `C:\Mini_erp\Plan.docx` | `C:\Mini_erp\P2.docx`

## Rules (`.claude/rules/`)
| File | Nội dung | Load khi |
|---|---|---|
| `project-overview.md` | Tech stack, cấu trúc thư mục, lệnh chạy, tài khoản demo | Luôn |
| `conventions.md` | Quy ước kiến trúc, naming, DB rules, mã tự động | Luôn |
| `phase-history.md` | Module map, services, FSM, migration prefix | Khi edit PHP/migration |
| `rbac.md` | 7 roles, 36 permissions, cách thêm permission mới | Khi edit seeder/routes/admin |
| `database-schema.md` | 41 bảng — schema đầy đủ nhóm theo module | Khi edit migration/model |
| `php-patterns.md` | Controller, Model, Service, Enum, Migration patterns | Khi edit PHP |
| `vue-patterns.md` | Index/Form/Show page, composables, StatusBadge patterns | Khi edit Vue/JS |

## Skills (`.claude/skills/`)
| File | Nội dung |
|---|---|
| `formulas.md` | Công thức sẵn dùng: tạo module mới, generateCode, FSM, PDF, permission, filter |

## Agents (`.claude/agents/`)
| Agent | Mục đích |
|---|---|
| `researcher` | So sánh lựa chọn kỹ thuật — luôn kết thúc bằng Recommendation |

## Trạng thái hiện tại
- **G1–G7 + Phase 8A–8D + Phase 9:** HOÀN THÀNH (50+ bảng, Docker/Deploy VPS ✅)
- **Purchasing:** Đơn mua hàng (MH-), Hóa đơn đầu vào, Hợp đồng mua ✅
- **Serial tracking:** Nhập kho + Xuất kho đều theo dõi serial number ✅; hủy phiếu đã confirmed → reversal movement + serial → `cancelled`; xóa phiếu nhập → PO liên kết tự sync trạng thái ✅
- **Stock entry bắt buộc qua PO:** tạo phiếu nhập chỉ được từ PO (không tự do); số lượng capped theo PO; hỗ trợ giao từng phần → PO status `partial_received` ✅
- **Products/Show:** bỏ serial list, chỉ hiển thị thông tin sản phẩm + tồn kho tổng ✅
- **Delivery tracking:** `stock_exits.order_id` liên kết XK với đơn hàng; `order_items.delivered_quantity` track tiến độ giao; OrderStatus thêm `partial_delivered`; OrderService.syncDelivery() gọi sau confirm XK; Dashboard widget cảnh báo đơn thiếu hàng ✅
- **Phase 9 — Leads/CRM Pipeline:** Lead model (LD-XXXX), LeadService FSM, 3 Vue pages, convert-to-customer ✅
- **Phase 9 — Stock Transfer (Chuyển kho):** StockTransfer/Item (CK-XXXX), serial tracking across warehouses ✅
- **Phase 9 — Sales Return (Trả hàng bán):** SalesReturn/Item (TH-XXXX), serial reversal (Sold→InStock) ✅
- **Phase 9 — Purchase Return (Trả hàng mua):** PurchaseReturn/Item (THM-XXXX), SerialStatus::ReturnedToSupplier ✅
- **Phase 9 — Notifications:** Database notifications, LowStock/TicketCreated/InvoiceOverdue, NotificationDropdown, 30s polling ✅
- **Phase 9 — Price Lists (Bảng giá):** PriceList/Item (BG-XXXX), auto-fill pricing in Order/Quotation forms ✅
- **Phase 9 — Bulk Import Excel:** ProductImport, CustomerImport, SupplierImport via maatwebsite/excel, template download ✅
- **Phase 9 — Audit Log UI:** ActivityLogController, filters by user/type/date ✅
- **Over-delivery alerts:** `order_over_deliveries` — alert khi xuất vượt đơn, tự resolve khi đơn bổ sung Completed, Dashboard widget đỏ ✅
- **In-app tab bar:** `useTabs.js` composable (localStorage, max 8 tabs) + `TabBar.vue` — tự động track navigation, click × đóng tab ✅
- **AR fix:** `InvoiceController.allowedActions()` bỏ `mark_paid` — invoice chỉ auto-Paid khi payment >= total qua `InvoiceService.addPayment()` ✅
- **Invoice form auto-fill:** Chọn Order → tự tìm Contract liên kết → fill subtotal/total từ `contract.value`; `step="1"` trên inputs ✅
- **Supplementary order link:** `orders.supplementary_for_order_id` — đơn bổ sung biết nó bù cho đơn nào; `OrderService.resolveOverDeliveriesForOrder()` ưu tiên explicit link trước heuristic; Dashboard hiển thị "Đang bổ sung: DH-XXXX" khi đã có đơn chờ ✅
- **Phase B — Fixed Asset Depreciation:** FixedAsset (900028-29), FixedAssetDepreciation, FixedAssetService (getSchedule/runMonthlyDepreciation), FixedAssetController (show/depreciate), Admin/FixedAssets/Show.vue với schedule table, CLI `assets:depreciate --period=YYYY-MM` ✅
- **Phase C — Kiểm kê kho:** InventoryCount/Item (IK-YYMMDDXX, 900030-31), InventoryCountService (populateItems/saveItems/confirm atomically), InventoryCountController, Warehouse/InventoryCounts/Index|Form|Show.vue — confirm gửi kèm items để save+confirm 1 lần ✅
- **Phase P1 — Nền tảng kế toán:** account_codes (136 TK TT200), accounting_periods, journal_entries/lines, AccountingService (post/reverse/getBalance), auto-posting trong InvoiceService/StockService/CashVoucherService, AccountCodeController/AccountingPeriodController/JournalEntryController ✅ (2026-05-28)
- **Phase P2 — Báo cáo từ journal_entry_lines + Chi phí trả trước:**
  - Rebuild 5 báo cáo kế toán từ journal_entry_lines: TrialBalance, GeneralJournal, AccountLedger (all accounts), BalanceSheet, IncomeStatement ✅
  - PrepaidExpense (CPT-), PrepaidExpenseAllocation, PrepaidExpenseService (create/amortize/runMonthlyAmortization) ✅
- **Phase P3 — Đối chiếu ngân hàng (Bank Reconciliation):**
  - BankAccount (bank_accounts), BankTransaction (bank_transactions), BankTransactionStatus enum ✅
  - BankReconciliationService: createTransaction/reconcile/unreconcile ✅
  - BankAccountController + BankTransactionController, Vue: BankAccounts/Index+Form, BankTransactions/Index ✅
- **Phase P4 — Công nợ chi tiết + Điều khoản thanh toán:**
  - PaymentTerm (payment_terms), PaymentTermController, Vue: PaymentTerms/Index+Form ✅
  - Customers: thêm credit_limit + payment_term_id; Suppliers: thêm payment_term_id ✅
  - ArDetailController (Sổ chi tiết TK 131 per customer from journal_entry_lines) ✅
  - ApDetailController (Sổ chi tiết TK 331 per supplier from journal_entry_lines) ✅
- **Phase P5 — Hóa đơn điện tử (HĐDT):**
  - Migration 200010: add e_inv_template/series/number/status/issued_at/cancel_reason to invoices ✅
  - InvoiceController: issueEInvoice() / cancelEInvoice() / eInvoicePdf() ✅
  - Invoice model: nextEInvoiceNumber() auto-sequence per series ✅
  - PDF template: resources/views/pdf/e-invoice.blade.php (mẫu HĐDT TT78) ✅
  - Invoices/Show.vue: HĐDT section (issue form / status / cancel / PDF download) ✅
  - Helper: App\Helpers\NumberToWords (số tiền bằng chữ tiếng Việt) ✅
- **Phase P6 — Payroll BHXH/BHYT/BHTN + PIT:**
  - Migration 200007-200009: users (dependents_count, pit_tax_code), payroll_items (insurance breakdown, pit), payrolls (totals) ✅
  - PitCalculatorService: BHXH 8%/17.5%, BHYT 1.5%/3%, BHTN 1%/1%, PIT lũy tiến 7 bậc, cap 46.8M ✅
  - PayrollService: tự động tính khi createPayroll/updateItem; journal entry khi confirm (Dr 6421 Cr 334/3335/3338/3389/3384) ✅
  - Payrolls/Show.vue: bảng breakdown BHXH/BHYT/BHTN/PIT per nhân viên, live preview khi sửa ✅
  - Admin/Users/Form.vue: thêm trường base_salary, allowance, dependents_count, pit_tax_code ✅
  - Migration tiếp theo: `2026_05_28_200011`
- **Phase P7 — Logic fixes + Automation + Report Guidance (2026-05-28):**
  - Fix InvoiceService: ngăn hạch toán trùng (kiểm tra journal_entry tồn tại trước khi post) ✅
  - Fix IncomeStatement: bỏ TK '511' cha khỏi tổng doanh thu (chỉ dùng 5111/5113) ✅
  - Fix CashFlow: thêm phiếu thu/chi (CashVoucher confirmed) vào dòng tiền ✅
  - Command `accounting:mark-overdue`: tự động chuyển Invoice Sent → Overdue khi qua due_date ✅
  - Command `accounting:amortize-prepaid {--period}`: phân bổ CPT hàng tháng ✅
  - Command `accounting:month-end {period}`: chạy toàn bộ nghiệp vụ cuối tháng (depreciate + amortize + mark-overdue) ✅
  - Schedule: mark-overdue daily 01:00, month-end ngày 28 mỗi tháng 02:00 ✅
  - Credit limit: InvoiceController.store() kiểm tra hạn mức công nợ KH, trả warning nếu vượt ✅
  - Dashboard: accountingAlerts widget (overdue count+amount, pending overdue, unreconciled bank, pending payroll) ✅
  - Guidance banners trên báo cáo: TrialBalance (alert Nợ≠Có), BalanceSheet (green/red balance status), IncomeStatement (alert lỗ), VAT (hướng dẫn 01/GTGT), CashFlow (hướng dẫn) ✅
- **Bảng chấm công (Attendance):** AttendanceSheet (CC-YYYYMM, 900032-33), AttendanceRecord (days jsonb), khóa tháng, nhập thủ công, chi tiết per NV ✅ (2026-06-03)
- **Khóa bảng lương:** payrolls.is_locked + locked_by + locked_at (900034), chỉ Admin mở khóa, guard trong updateItem/confirm/destroy ✅ (2026-06-03)
- **Phase PX — Tách luồng Dự án / Thương mại (2026-06-03):**
  - Enum ItemUsageType (commercial|project); stock_exits.item_usage_type + project_id (900035) ✅
  - JournalEntryLine.project_id — theo dõi chiều dự án trên sổ kế toán (900036) ✅
  - project_wip_entries — chi phí dở dang TK 154 per dự án (900037) ✅
  - ProjectWipService: createFromStockExit() / getWipSummary() / getWipEntries() / recognizeCost() ✅
  - StockService::postExitJournal(): khi item_usage_type=project → Nợ 154 / Có 156 (thay vì 632) ✅
  - AccountingService: project_id per line (truyền qua $line['project_id']) ✅
  - StockExit Form: dropdown "Mục đích xuất kho" + project selector khi chọn project ✅
  - Project Show: tab "Chi phí dở dang (TK 154)" với WIP summary, bảng entries, nút kết chuyển 632 ✅
  - Route: POST projects/{project}/recognize-cost (can:accounting.manage) ✅
- **Liên kết PO → Dự án (2026-06-03):**
  - Migration 900038: purchase_orders.project_id nullable FK → projects ✅
  - PO Form: dropdown chọn dự án (lọc planning/in_progress) ✅
  - PO Show: hiển thị dự án liên kết kèm link click-through ✅
  - Project Show: tab "Đơn mua hàng" — danh sách PO của dự án + tổng tiền ✅

## Quy tắc quan trọng
- `cost_price` trên sản phẩm = giá **đã gồm VAT** (tổng trả NCC)
- `total_cost` = `cost_price + business_cost` (không cộng thêm VAT)
- `unit_price` trong đơn mua = giá đã gồm VAT → khi tạo hóa đơn dùng back-calculate để tách subtotal/tax
- Tồn kho = `SUM(stock_movements.quantity)` — không lưu trực tiếp
- Soft delete chỉ cho master data (products, customers, suppliers, users, services)
