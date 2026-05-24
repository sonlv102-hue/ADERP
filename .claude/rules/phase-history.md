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
| **Next** | **900022** | — |

## Services & FSM
| Service | Models | Key transitions |
|---|---|---|
| InvoiceService | Invoice, Payment | Draft→Sent→(Paid\|Overdue); addPayment() auto-marks Paid |
| PurchaseInvoiceService | PurchaseInvoice, PurchaseInvoicePayment | pending→received→reviewing→valid→(partial_paid\|paid) |
| StockEntryService | StockEntry, StockMovement, ProductSerial | confirm() creates movement + serial InStock; cancel() reversal |
| StockExitService | StockExit, StockMovement, ProductSerial | confirm() creates movement + serial Sold/Returned; cancel() reversal |
| StockTransferService | StockTransfer, StockTransferItem | confirm() creates exit+entry movements across warehouses |
| OrderService | Order, OrderItem | syncDelivery() updates delivered_quantity from stock exits |
| ProjectService | Project, Task | Status: planning→active→on_hold→completed\|cancelled |
| TicketService | Ticket, TicketLog | New→Assigned→InProgress→Resolved→Closed |
| LeadService | Lead | New→Contacted→Qualified→(Lost\|Converted); convertToCustomer() |
| SalesReturnService | SalesReturn, SalesReturnItem | confirm() reversal stock + serial Sold→InStock |
| PurchaseReturnService | PurchaseReturn, PurchaseReturnItem | confirm() negative stock movement + serial →ReturnedToSupplier |

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
- **Extras:** In-app TabBar (useTabs.js), Delivery tracking (order_items.delivered_quantity), Serial tracking in entries/exits
