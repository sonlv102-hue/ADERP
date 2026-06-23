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
| Phase I (2026-06-15) | 900085–900087 | Fund Transfer (LCQ-), Balance Sheet tabs (TK chưa map), Payroll Adjustment |
| **Phase J (2026-06-15)** | **900088–900114** | Fixed Assets full overhaul, Supplier Advance/331UT, Personal Finance, Payroll accounting, AR/AP CashVoucher, Prepayment Offset |
| **Phase L (2026-06-18–19)** | **900115–900131** | CCDC (small_tools 7 tables), Customer Advance/131UT, Search indexes, AVCO seed/backfill |
| **Phase M (2026-06-19–21)** | **900132–900146** | AVCO engine (inventory_balances), Project Direct Materials, WIP corrections, StockExit multi-PO, Invoice per-line items |
| **Phase N (2026-06-21–22)** | **900200–900210** | Project expense extensions (labor/PIT/contractor/fixed_asset), Extra cost transfers (154 batch), B02/B03 TT133, cash_flow_code |
| **Next (900xxx)** | **900211** | Tiếp tục sau 2026_06_22_900210 (date prefix: 2026_06_23_) |
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
| FundTransferService | FundTransfer, Fund | post() Dr/Cr fund accounts; reverse(); cancel() |
| FixedAssetDepreciationService | FixedAsset, FixedAssetDepreciation | runMonthly(period); schedule view |
| FixedAssetJournalService | FixedAsset, JournalEntry | postAcquisition(); postDisposal(); postMovement() |
| SupplierAdvanceService | SupplierOpeningAdvance, SupplierAdvanceAllocation | create() enforce 331UT; allocate() Dr3311/Cr331UT khi khác TK |
| CustomerAdvanceService | CustomerOpeningAdvance, CustomerAdvanceAllocation | create() enforce 131UT; allocate() |
| SmallToolService | SmallTool, SmallToolReceipt/Issue/Allocation/Disposal | confirm() Dr1531/Cr331; issue() Dr2422/Cr1531; allocate() phân bổ chi phí |
| SmallToolJournalService | SmallTool, JournalEntry | postAcquisition(); postIssue(); postAllocation() |
| AvcoService | InventoryBalance, StockMovement | recordEntry(); recordExit(); initializeFromOpeningBalance(); getBalance() |
| ProjectDirectMaterialService | ProjectDirectMaterial | store() 3 loại: tracking_only/invoice_link/journal_entry; assertNoDoublePost() |
| ProjectWipCorrectionService | ProjectWipEntry, JournalEntry | correction flow Dr/Cr 154 |
| ProjectExtraCostTransferService | ProjectExtraCostTransfer, ProjectWipEntry | previewBatch(); transferBatch(); reverse() |
| PersonalLoanService | PersonalLoan, PersonalLoanRepayment | confirm(); repay() |
| PersonalExpenseService | PersonalExpenseReport, PersonalExpenseLine | confirm() + JE |
| ArApLedgerService | (utility) | Mọi màn AR/AP phải dùng service này — không tự query invoice/purchase_invoice riêng |
| PayrollRollbackService | Payroll, PayrollItem | rollback confirmed payroll |
| SystemHealthService | (utility) | 10 checks độc lập — seed, FK, orphan, migration drift |
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
  - Fund Transfer (LCQ-): fund_transfers, FundTransferService (post/reverse/cancel)
  - Balance Sheet: tabs "Bảng cân đối" / "TK chưa map" / "Kiểm tra cân đối"
  - Payroll Adjustment: adjustment_amount/reason/taxable per payroll_item
- **Phase J (2026-06-15):**
  - Fixed Assets full: fixed_asset_categories, fixed_asset_movements, fixed_asset_repairs, fixed_asset_disposals; FixedAssetService + FixedAssetJournalService + FixedAssetDepreciationService; TT45 fields
  - Purchase Invoice Type (9 loại): PurchaseInvoiceType enum, 3311 vs 3312 routing per type
  - Supplier Advance / TK 331UT: supplier_opening_advances, supplier_advance_allocations; SupplierAdvanceService; allocate() Dr3311/Cr331UT
  - Personal Finance: shareholders (TV-), personal_loans (PVay-), personal_expense_reports (PCH-)
  - Payroll bút toán: Cr 3341/3335/33831/33832/33841/33842/3385/33821; chi lương qua Fund → CashVoucher PC-
  - balance_sheet_account_mappings; fix parent account codes (TK 112→1121)
- **Phase K (2026-06-17):**
  - AR/AP Cash Voucher integration: cash_voucher_id trên payments + purchase_invoice_payments; PT-/PC- tự động
  - Supplier Prepayment Offset: payment_type cash/offset/combined trên purchase_invoice_payments
  - Period close enhancements; purchase_order_id nullable trên purchase_invoices
- **Phase L (2026-06-18–19):**
  - CCDC: small_tool_categories, small_tools, small_tool_receipts, small_tool_issues, small_tool_allocations, small_tool_transfers, small_tool_disposals; SmallToolService + SmallToolJournalService + SmallToolAllocationService
  - Customer Advance / TK 131UT: customer_opening_advances, customer_advance_allocations; CustomerAdvanceService
  - Search: RemoteSearchSelect.vue + SearchController (8 endpoints); search indexes migration 900123/900209
  - source_item_id trên stock_movements (traceability)
  - AVCO seed: advance account codes 331UT/131UT; supplier advance reversal fields
- **Phase M (2026-06-19–21):**
  - AVCO engine: inventory_balances (product+warehouse UNIQUE, qty/value/avg_cost); cost_source='avco'|'fifo'|'legacy' trên stock_exit_items; AvcoService wired vào StockService
  - Purchase Invoice per-line: purchase_invoice_items (per-line account_code, project_id, credit_account)
  - Project Direct Materials: project_direct_materials; ProjectDirectMaterialService (3 loại, chống double-post)
  - Project WIP corrections: project_wip_correction_logs; ProjectWipCorrectionService
  - StockExit redesign: purchase_order_id trên stock_exits; stock_exit_purchase_orders junction; order_item_id trên stock_exit_items; multi-PO filter trên Form.vue
  - Invoice per-line: invoice_items; InvoiceController cogs_status
  - TK 621/623 seeded; project_expenses extended (debit/credit account, supplier, VAT, payment_method)
- **Phase N (2026-06-21–22):**
  - Project expense modes: labor_type, PIT withholding, contractor fields, fixed_asset link, cash_voucher link; transfer_status cancelled/data_error
  - Project Extra Cost Transfers (kết chuyển 154 batch): project_extra_cost_transfers; ProjectExtraCostTransferService (previewBatch/transferBatch)
  - B02-DNN IncomeStatementService TT133 rewrite (cột Năm trước)
  - B03-DNN CashFlowStatementService TT133 (direct method, phân loại theo TK đối ứng); cash_flow_code trên cash_vouchers
  - WIP cancel for PurchaseInvoice source; admin force-delete stock exit
  - Internal Transfer Report: period filter (month/year/custom/all)
  - RemoteSearchSelect đợt 2: 9 forms nữa (SupplierAdvances, PrepaidExpenses, FixedAssets, PurchaseContracts, CustomerAdvances, Invoices, Projects, Sales/Contracts, Commissions)
- **Extras:** In-app TabBar (useTabs.js), Delivery tracking, Serial tracking, Backup module, Opening Balance Excel import, Mobile Responsive (2026-06-19), Admin System Health (SystemHealthService, 10 checks), Dashboard KPI widget, Account Ledger TK cha→con, Draft JE badge
