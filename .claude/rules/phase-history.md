# Mini ERP — Phase History & Module Map

## Migration Prefixes
| Phase | Prefix range | Description |
|---|---|---|
| G1 | 000001–000010 | Foundation, auth, users |
| G2 | 100001–100013 | CRM, catalog, warehouse |
| G3 | 200001–200005 | Sales (quotations, orders, contracts) |
| G4 | 300001–300007 | Projects, tasks, materials |
| G5 | 400001–400004 | Tickets, warranties |
| G6 | 500001–500004 | Invoices, payments |
| G7 | 600001–600003 | Purchase orders, suppliers bank |
| Phase 8 | 700001–800005 | Settings, documents, purchase invoices, commissions, reports |
| Phase 9 | 900001–900021 | Leads, stock transfers, returns, notifications, price lists, imports |
| Phase 9+ | 900022–900027 | Funds, CashVouchers, PurchaseContracts, delivery tracking extras |
| Phase B | 900028–900029 | Fixed asset depreciation (FixedAssetDepreciation, last_depreciation_period) |
| Phase C | 900030–900031 | Inventory counts (InventoryCount, InventoryCountItem) |
| Phase D (2026-06-03) | 900032–900044 | Attendance, payroll lock, project WIP/links, VAT per line, FK+indexes |
| Phase D extras | 300004–300005 | Employee/payroll allowance breakdown (2026-06-03, reuse G4 date prefix) |
| Phase E (2026-06-05) | 100001–100006 | Bank transaction enhancements, supplier/internal bank accounts (date 2026_06_05, distinct từ G2) |
| Phase F (2026-06-07) | 900045–900065 | Accounting audit: COGS snapshot, revenue mapping, PIT config, AR/AP cleanup, Journal index |
| Phase G (2026-06-13) | 900066–900078 | Project FIFO lots, StockEntry/Exit lot columns, JournalEntry void workflow |
| Phase H (2026-06-14) | 900079–900084 | Period close batches, JE edit/unpost, AccountingSettings |
| **Phase I (2026-06-15)** | **900085–900087** | Fund Transfer (LCQ-), Balance Sheet tabs (TK chưa map), Payroll Adjustment |
| **Next (900xxx)** | **900088** | Tiếp tục sau 2026_06_15_900087 |
| **Next (bank/E series)** | **100007** | Tiếp tục sau 2026_06_05_100006 nếu cùng chủ đề |

## Services & FSM
| Service | Models | Key transitions |
|---|---|---|
| InvoiceService | Invoice, Payment | Draft→Sent→(Paid\|Overdue); addPayment() auto-marks Paid |
| PurchaseInvoiceService | PurchaseInvoice, PurchaseInvoicePayment | pending→received→reviewing→valid→(partial_paid\|paid) |
| StockEntryService (StockService) | StockEntry, StockMovement, ProductSerial | confirmEntry() + cancelEntry() + recallEntry(); hạch toán Dr156/Cr331 |
| StockExitService (StockService) | StockExit, StockMovement, ProductSerial | confirm() creates movement + serial Sold/Returned; cancel() reversal |
| StockTransferService | StockTransfer, StockTransferItem | confirm() creates exit+entry movements across warehouses |
| OrderService | Order, OrderItem | syncDelivery() updates delivered_quantity from stock exits |
| ProjectService | Project, Task | Status: planning→active→on_hold→completed\|cancelled |
| ProjectWipService | ProjectWipEntry, JournalEntry | Ghi nhận WIP vào TK 154; recognizeCost() kết chuyển giá vốn |
| TicketService | Ticket, TicketLog | New→Assigned→InProgress→Resolved→Closed |
| LeadService | Lead | New→Contacted→Qualified→(Lost\|Converted); convertToCustomer() |
| SalesReturnService | SalesReturn, SalesReturnItem | confirm() reversal stock + serial Sold→InStock |
| PurchaseReturnService | PurchaseReturn, PurchaseReturnItem | confirm() negative stock movement + serial →ReturnedToSupplier |
| FixedAssetService | FixedAsset, FixedAssetDepreciation | runMonthlyDepreciation(period) batch; getSchedule() posted+projected |
| InventoryCountService | InventoryCount, InventoryCountItem | populateItems() snapshot stock; saveItems(); confirm() atomic save+adjust |
| PayrollService | Payroll, PayrollItem, Employee | confirm() → tính lương theo attendance; payEmployee() cá nhân |
| PitCalculatorService | (utility) | Tính PIT theo biểu thuế lũy tiến VN |
| CashVoucherService | CashVoucher, Fund | confirm() → cập nhật số dư quỹ + bút toán |
| AccountingService | JournalEntry, JournalEntryLine | post() + markPosted() + reverseOrDelete() + reverse() + createDraft() + updateLines() + unpost() + restoreOriginalLines() |
| AccountingSettings | (helper, no model) | get(key, default) — request-level cache; clearCache() sau update |
| BankReconciliationService | BankTransaction, JournalEntry | reconcile() + unreconcile() |
| PeriodCloseService | AccountingPeriod, PeriodCloseBatch, JournalEntry | close(period) idempotent; getPeriodBalances(); kết chuyển Dr/Cr 911 ↔ 4212 |

## Completed Modules
- **G1:** Auth, Users, Admin CRUD
- **G2:** Customers, Suppliers, Products, Services, Categories, Warehouses, StockEntries, StockExits, Serials
- **G3:** Quotations (BG-), Orders (DH-), Contracts (HD-)
- **G4:** Projects (DA-), Tasks, Members, Materials, Expenses
- **G5:** Tickets (TK-), Warranties
- **G6:** Invoices (HĐ-), Payments, Dashboard
- **G7:** PurchaseOrders (MH-), Docker/Deploy
- **Phase 8:** Settings, Documents (CT-), PurchaseInvoices, PurchaseContracts, Commissions, Reports
- **Phase 9:** Leads (LD-), StockTransfers (CK-), SalesReturns (TH-), PurchaseReturns (THM-), Notifications, PriceLists, BulkImport, AuditLogUI
- **Phase B:** FixedAssets — straight-line depreciation, schedule view, batch depreciate action, CLI command
- **Phase C:** InventoryCounts (IK-) — warehouse snapshot, counted qty input, atomic save+confirm, adjustment StockMovements
- **Phase D (2026-06-03):**
  - Attendance: AttendanceSheets (CC-), AttendanceRecords — chấm công theo tháng, lock/unlock, sửa từng record
  - Payroll lock: payrolls.is_locked — khóa bảng lương sau khi đã chốt
  - Employee allowances: allowance_breakdown JSON trên employees, allowance_detail trên payroll_items
  - Project WIP: ProjectWipEntry, ProjectWipService, stock_exits.project_id, TK 154, recognizeCost()
  - PO links: purchase_orders.project_id + order_id — liên kết PO với dự án và đơn hàng
  - project_members chuyển từ user_id → employee_id (migration 900039)
  - VAT per line: vat_rate trên order_items, quotation_items, purchase_order_items
  - journal_entry_lines.project_id — chiều dự án trên từng dòng bút toán
  - FK + indexes tổng hợp (migration 900044)
- **Phase E (2026-06-05):**
  - BankTransaction enhancements: import_hash (unique per account), counterpart bank/account/name, tx_type, internal_status/note/return_amount
  - SupplierBankAccounts — tài khoản ngân hàng NCC (nhiều tài khoản, chọn primary)
  - InternalBankAccounts — tài khoản nội bộ công ty để phân loại chuyển khoản nội bộ
  - InternalTransferReport — báo cáo và cập nhật trạng thái chuyển khoản nội bộ
- **Phase F (2026-06-07):**
  - COGS snapshot per order_item (unit_cogs_source), revenue_account_code per order_item
  - KPCĐ employer (TK 3382), PIT config dynamic (pit_configs, employee_dependents)
  - AR/AP ledger unification (ArApLedgerService), per-customer/supplier TK (receivable/payable_account_code)
  - Accounting audit: validateLines() từ chối TK tổng hợp, audit-parent-accounts command
  - B01a-DNN Balance Sheet (FinancialPositionReportService), TrialBalance tách opening
  - Reclassification JEs (payable_reclassification), JE source_type + fiscal_period
- **Phase G (2026-06-13):**
  - Project FIFO Lots: project_inventory_lots + stock_exit_item_lot_allocations
  - StockService: confirmEntry() tạo lot, confirmExit() FIFO allocate, cancelExit() restore
  - JournalEntry Void: voided_at/voided_by/void_reason; FSM posted→reversed→voided
  - PeriodCloseService: kết chuyển tháng idempotent, accounting:period-close command
  - StockExit cancel guard: block nếu invoice đang sent/overdue
- **Phase H (2026-06-14):**
  - JournalEntry Edit/Unpost: edited_by_user, edit_reason, original_lines (jsonb); createDraft(), updateLines(), unpost(), restoreOriginalLines()
  - AccountingSettings: bảng accounting_settings (31 keys) — TK cấu hình được cho tất cả auto-posting services
  - Product: item_type + revenue_account_code + inventory_account UI trên Form/Show
  - ProductCategory: revenue_account_code UI trên Form
- **Phase I (2026-06-15):**
  - Fund Transfer (LCQ-): bảng fund_transfers, FundTransferStatus enum, FundTransferService (post/reverse/cancel), resolveFundAccount() ưu tiên fund.account_code rồi fallback AccountingSettings
  - Fund: account_code column (FK tuỳ chọn vào chi tiết TK 1121/1111); balance() tính thêm transfers_in/out; Funds/Form + Index updated
  - Balance Sheet: thêm 3 tabs — "Bảng cân đối", "TK chưa map" (badge count), "Kiểm tra cân đối"; unmappedAccounts passed từ controller
  - Payroll Adjustment: payroll_items.adjustment_amount/reason/taxable/adjusted_by/adjusted_at; payrolls.total_adjustment; PayrollService.updateAdjustment(); thuc_linh = net_salary + adjustment_amount - advance; JE Dr bao gồm adjustment khi taxable=true; Payrolls/Show.vue: cột "Điều chỉnh" + adjForm trong edit modal
- **Extras:** In-app TabBar (useTabs.js), Delivery tracking (order_items.delivered_quantity), Serial tracking in entries/exits, Backup module (BackupController), Opening Balance Excel import
