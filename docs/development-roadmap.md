# Mini ERP — Development Roadmap

## Project Overview
Hệ thống quản lý nội bộ (ERP) cho công ty kinh doanh và thi công giải pháp IT.

## Phases & Status

### Phase 1–4: Foundation & Core Modules ✅ COMPLETED
- **G1:** Foundation (Laravel, Auth, Admin layout)
- **G2:** CRM + Warehouse (Catalog, Customers, Suppliers, Stock management)
- **G3:** Sales (Quotations, Orders, Contracts)
- **G4:** Project Management (Projects, Tasks, Expenses)

### Phase 5: Support & Warranty ✅ COMPLETED (2026-05-21)
- Tickets & Ticket Logs
- Warranty management
- TicketService with FSM

### Phase 6: Accounting & Dashboard ✅ COMPLETED (2026-05-21)
- Invoices & Payments
- InvoiceService with FSM
- Dashboard with charts & analytics

### Phase 7: Docker & Deployment ✅ COMPLETED (2026-05-21)
- Docker containerization
- VPS deployment
- Production setup

### Phase 8A–8D: Stock & Delivery Enhancements ✅ COMPLETED
- Serial tracking for stock movements
- Partial delivery support (PartialReceived, PartialDelivered)
- Mandatory stock entry via PO
- Order delivery tracking & sync

### Phase 9: CRM Pipeline, Returns & Logistics ✅ COMPLETED (2026-05-23)

#### 1. Leads/CRM Pipeline ✅
- Lead model (LD-XXXX prefix)
- LeadService with FSM
- 3 Vue pages: Index, Form, Show
- Convert-to-customer workflow
- Permissions: leads.view/create/edit/delete

#### 2. Stock Transfer (Chuyển kho) ✅
- StockTransfer & StockTransferItem models (CK-XXXX prefix)
- StockTransferService with FSM
- Full serial tracking across warehouses
- Permissions: stock-transfers.view/create/edit/delete

#### 3. Sales Return (Trả hàng bán) ✅
- SalesReturn & SalesReturnItem models (TH-XXXX prefix)
- SalesReturnService with FSM
- Serial status reversal: Sold → InStock
- Permissions: sales-returns.view/create/edit/delete

#### 4. Purchase Return (Trả hàng mua) ✅
- PurchaseReturn & PurchaseReturnItem models (THM-XXXX prefix)
- PurchaseReturnService with FSM
- New SerialStatus: ReturnedToSupplier
- Permissions: purchase-returns.view/create/edit/delete

#### 5. Notifications ✅
- Laravel database notifications (Notifications table)
- LowStock, TicketCreated, InvoiceOverdue channels
- NotificationDropdown in TopBar
- 30-second polling mechanism

#### 6. Price Lists (Bảng giá) ✅
- PriceList & PriceListItem models (BG-XXXX prefix)
- Integration into Order/Quotation forms
- Auto-fill pricing for products
- Permissions: price-lists.view/create/edit/delete

#### 7. Bulk Import Excel ✅
- ProductImport via maatwebsite/excel
- CustomerImport via maatwebsite/excel
- SupplierImport via maatwebsite/excel
- Import buttons on Index pages
- Template download functionality

#### 8. Audit Log UI ✅
- ActivityLogController for reading activity_log table
- Filters by user/type/date range
- Detailed change tracking

## Database Changes (Phase 9)
- **New tables:** leads, stock_transfers, stock_transfer_items, sales_returns, sales_return_items, purchase_returns, purchase_return_items, price_lists, price_list_items, notifications (Laravel built-in)
- **FK additions:** product_serials table extended with stock_transfer_item_id, sales_return_item_id, purchase_return_item_id
- **Migrations:** 900009–900021 (13 new migrations)

## Next Phase: Phase 10 (TBD)
Pending feature requirements and prioritization.

## Key Metrics
- **Total Modules:** 15+
- **Total Database Tables:** 50+
- **Permissions:** 7 roles, 50+ permissions
- **Deployment:** Docker + VPS ✅

## Important Notes
- `cost_price` = giá đã gồm VAT (tổng trả NCC)
- Tồn kho = SUM(stock_movements.quantity)
- Soft delete: chỉ cho master data (products, customers, suppliers, users, services)
- Serial tracking: track status changes (InStock, Sold, ReturnedToSupplier, Cancelled)
