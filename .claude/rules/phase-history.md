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
| Phase E (2026-06-05) | 100001–100005 | Bank transaction enhancements, supplier/internal bank accounts (date 2026_06_05, distinct từ G2) |
| **Next (900xxx)** | **900045** | Tiếp tục sau 2026_06_04_900044 |
| **Next (bank/E series)** | **100006** | Tiếp tục sau 2026_06_05_100005 nếu cùng chủ đề |

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
| AccountingService | JournalEntry, JournalEntryLine | post() + markPosted() + reverseOrDelete() + reverse() |
| BankReconciliationService | BankTransaction, JournalEntry | reconcile() + unreconcile() |

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
- **Extras:** In-app TabBar (useTabs.js), Delivery tracking (order_items.delivered_quantity), Serial tracking in entries/exits, Backup module (BackupController), Opening Balance Excel import
