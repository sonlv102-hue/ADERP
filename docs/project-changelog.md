# Mini ERP — Project Changelog

All significant changes, features, and fixes are documented below.

## [2026-06-09] — AR/AP Unified Ledger Service

### New
- **`ArApLedgerService`** (`app/Services/ArApLedgerService.php`) — service lõi dùng chung cho toàn bộ màn công nợ AR/AP. Merge invoices + opening_balance thành unified DTO, tính aging buckets, in-memory pagination tương thích Inertia.
- **AP Opening Balance pay()** — `ArApOpeningBalanceController::pay()` mở rộng hỗ trợ cả AP (Dr 331 / Cr 111/112) và AR (Dr 111/112 / Cr 131). Route: `accounting.ar-ap-opening-balance.pay`.

### Updated
- `ArCollectionController`, `ApPaymentController`, `ARAgingController`, `APAgingController` — dùng `ArApLedgerService` thay vì self-query.
- `ARAgingExport`, `APAgingExport` — thêm cột "Nguồn" (Hóa đơn / Đầu kỳ).
- Vue pages: `ArCollections/Index.vue`, `ApPayments/Index.vue`, `Reports/AR/Index.vue`, `Reports/AP/Index.vue` — prop `items`/`rows`, badge "Đầu kỳ", conditional link by source_type, composite key `source_type-id`.
- `CLAUDE.md` — thêm rule AR/AP phải dùng ArApLedgerService.

### Docs
- `docs/AR_AP_LOGIC.md` — mô tả đầy đủ logic AR/AP, DTO, aging, bút toán, quy tắc không vi phạm.
- `docs/TEST_LOG.md` — log commands + results.

---

## [2026-05-23] — Phase 9: CRM Pipeline, Returns & Logistics

### New Features
1. **Leads/CRM Pipeline** (LD-XXXX)
   - Lead model with status FSM (New, Contacted, Qualified, Lost, Converted)
   - LeadService for state management
   - 3 Vue pages: Index (list with filters), Form (create/edit), Show (detail view)
   - Convert-to-customer workflow
   - New permissions: leads.view, leads.create, leads.edit, leads.delete

2. **Stock Transfer (Chuyển kho)** (CK-XXXX)
   - StockTransfer & StockTransferItem models
   - StockTransferService with FSM (Draft, Confirmed, Completed, Cancelled)
   - Serial number tracking across warehouses
   - Automatic stock movement recording
   - New permissions: stock-transfers.view, stock-transfers.create, stock-transfers.edit, stock-transfers.delete

3. **Sales Return (Trả hàng bán)** (TH-XXXX)
   - SalesReturn & SalesReturnItem models
   - SalesReturnService with FSM
   - Serial reversal: Sold → InStock
   - Credit memo integration
   - New permissions: sales-returns.view, sales-returns.create, sales-returns.edit, sales-returns.delete

4. **Purchase Return (Trả hàng mua)** (THM-XXXX)
   - PurchaseReturn & PurchaseReturnItem models
   - PurchaseReturnService with FSM
   - New SerialStatus: ReturnedToSupplier
   - Debit memo integration
   - New permissions: purchase-returns.view, purchase-returns.create, purchase-returns.edit, purchase-returns.delete

5. **Notifications System**
   - Laravel database notifications table
   - 3 built-in notification classes:
     - LowStockNotification (when stock falls below threshold)
     - TicketCreatedNotification (new support ticket)
     - InvoiceOverdueNotification (unpaid invoices)
   - NotificationDropdown component in TopBar
   - Real-time polling (30-second interval)
   - Mark as read/unread functionality

6. **Price Lists (Bảng giá)** (BG-XXXX)
   - PriceList & PriceListItem models
   - Integration into Order and Quotation forms
   - Auto-fill product pricing
   - Support for quantity-based pricing tiers
   - New permissions: price-lists.view, price-lists.create, price-lists.edit, price-lists.delete

7. **Bulk Import (Excel)**
   - ProductImport: Import products with categories, pricing
   - CustomerImport: Import customers with contact info
   - SupplierImport: Import suppliers with payment terms
   - Uses maatwebsite/excel package
   - Import buttons on Index pages
   - Template download feature
   - Validation with error reporting

8. **Audit Log UI**
   - ActivityLogController with filters
   - Filter by user, action type, date range
   - Display user, action, model, changes
   - Read-only interface

### Database Migrations
- **900009:** Create leads table
- **900010:** Create stock_transfers & stock_transfer_items tables
- **900011:** Add FK to product_serials (stock_transfer_item_id)
- **900012:** Create sales_returns & sales_return_items tables
- **900013:** Add FK to product_serials (sales_return_item_id)
- **900014:** Create purchase_returns & purchase_return_items tables
- **900015:** Add FK to product_serials (purchase_return_item_id)
- **900016:** Create notifications table (Laravel built-in)
- **900017:** Create price_lists & price_list_items tables
- **900018–900021:** Import and Audit Log infrastructure

### API Changes
- No breaking changes to existing APIs
- New endpoints for each module (CRUD operations)
- Existing stock movement logic unchanged

### Performance Improvements
- Indexed all new FK columns
- Price list caching for order form performance
- Notification polling with debouncing

### Security
- All new modules follow existing RBAC pattern
- Soft delete disabled for transactional tables (returns, transfers)
- Audit logging enabled for all data changes

---

## [2026-05-21] — Phase 8D: Delivery Tracking & Order Sync

### New Features
- Order delivery tracking with PartialDelivered status
- StockExit.order_id linking to Orders
- OrderItem.delivered_quantity for progress tracking
- OrderService.syncDelivery() called after stock exit confirmation
- Dashboard widget for shortage alerts

---

## [2026-05-21] — Phase 8C: Stock Entry via PO

### New Features
- Mandatory stock entry through PO (no standalone entry)
- Stock quantity capped by PO quantity
- Support for partial delivery (PartialReceived status)

---

## [2026-05-21] — Phase 8A–8B: Serial Tracking & Partial Delivery

### New Features
- Serial tracking for all stock movements
- PartialReceived status for PO items
- Serial number persistence through purchase/sale cycle

---

## [2026-05-21] — Phase 7: Docker & Deployment ✅

### Features
- Docker containerization (Laravel + PostgreSQL + Nginx)
- VPS deployment automation
- Environment configuration

---

## [2026-05-21] — Phase 6: Accounting & Dashboard ✅

### Features
- Invoice & Payment management
- InvoiceService with FSM
- PDF invoice generation
- Dashboard with analytics charts

---

## [2026-05-21] — Phase 5: Support & Warranty ✅

### Features
- Ticket management system
- Warranty tracking
- TicketService with FSM

---

## Earlier Phases (1–4) ✅

Foundation, CRM, Sales, and Project Management modules completed.
