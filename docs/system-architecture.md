# Mini ERP — System Architecture

## Overview
Mini ERP is a comprehensive business management system built with Laravel and Vue.js, designed to handle CRM, sales, warehouse management, project management, support tickets, accounting, and logistics operations.

## Module Architecture

### Core Modules (Phase 1–4)

#### 1. Authentication & Authorization
- Laravel Fortify (email/password)
- 7 Roles: Admin, Manager, Supervisor, Staff, Customer, Supplier, Guest
- 50+ Permissions with RBAC
- Route middleware for permission checks

#### 2. CRM (Customer Relationship Management)
- **Customers:** Contact info, payment terms, credit limit
- **Suppliers:** Company details, banking info, tax ID
- **Products:** Catalog with categories, pricing, stock tracking
- **Services:** Labor and consulting services

#### 3. Sales & Quotations
- **Quotations** (TG-XXXX): Draft → Approved → Converted
- **Orders** (ĐH-XXXX): New → Confirmed → PartialDelivered/Delivered → Completed
- **Contracts** (HĐ-XXXX): Purchase agreements, payment schedules
- Integration with inventory for auto-confirmation

#### 4. Purchase Management
- **Purchase Orders** (MH-XXXX): New → Confirmed → PartialReceived/Received → Completed
- **Purchase Returns** (THM-XXXX): Return goods to suppliers
- Linked to invoicing

#### 5. Warehouse Management
- **Stock Movements:** Entry (NK), Exit (XK), Adjustment (ĐK), Transfer (CK)
- **Serial Tracking:** Track individual unit status through lifecycle
- **Stock Transfer** (CK-XXXX): Inter-warehouse movement with serial tracking
- **Low Stock Alerts:** Automatic notifications

#### 6. Project Management
- **Projects** (DA-XXXX): Planning, budgeting, task allocation
- **Tasks:** Assignment, progress tracking, status FSM
- **Materials & Equipment:** Inventory tracking
- **Expenses:** Labor, equipment, miscellaneous costs
- **Billing:** Project-based invoicing

#### 7. Support & Warranty
- **Tickets** (TK-XXXX): Customer issues, FSM (New → Assigned → InProgress → Resolved → Closed)
- **Warranties:** Product coverage periods, claim tracking
- **Notifications:** Alert on new tickets, overdue items

#### 8. Sales Returns
- **Sales Returns** (TH-XXXX): Customer product returns
- **Serial Reversal:** Sold → InStock status change
- **Credit Memos:** Automatic credit notes

#### 9. Accounting & Invoicing
- **Invoices** (HĐ-XXXX): Outbound (sale) & inbound (purchase)
- **Payments:** Payment tracking, reconciliation
- **Financial Reports:** Revenue, expenses, profit/loss

#### 10. CRM Pipeline (New)
- **Leads** (LD-XXXX): Prospects, FSM (New → Contacted → Qualified → Lost/Converted)
- **Conversion:** Lead → Customer workflow
- **Tracking:** Touch points, activity history

#### 11. Price Management (New)
- **Price Lists** (BG-XXXX): Tiered pricing, customer-specific rates
- **Integration:** Auto-fill in orders, quotations
- **Effective Dates:** Time-based pricing rules

#### 12. Bulk Import (New)
- **Excel Import:** Products, customers, suppliers
- **Validation:** Error reporting, rollback capability
- **Templates:** Download for standardized data entry

#### 13. Notifications (New)
- **Database Notifications:** Persistent message queue
- **Channels:** LowStock, TicketCreated, InvoiceOverdue
- **Polling:** Real-time UI updates (30-second interval)

#### 14. Audit Logging
- **Activity Log:** User actions, data changes, timestamps
- **UI Filters:** By user, action type, date range
- **Compliance:** Audit trail for regulatory requirements

---

## Data Model Architecture

### Key Entity Relationships

```
User
  ├─ Role (RBAC)
  ├─ Activity Logs
  └─ Notifications

Customer
  ├─ Orders (quotations, contracts)
  ├─ Invoices
  ├─ Tickets
  └─ Leads (prospect conversion)

Supplier
  ├─ Purchase Orders
  ├─ Invoices (inbound)
  └─ Agreements

Product
  ├─ Product Serials (individual units)
  ├─ Stock Movements
  │   ├─ Stock Entry (NK)
  │   ├─ Stock Exit (XK)
  │   ├─ Stock Transfer (CK)
  │   ├─ Stock Adjustment (ĐK)
  │   └─ Stock Reversal (sales/purchase returns)
  ├─ Price Lists
  └─ Inventory tracking

Order
  ├─ Order Items (with quantity & pricing)
  ├─ Stock Exits (delivery)
  ├─ Invoices
  └─ Deliveries (partial tracking)

Purchase Order
  ├─ PO Items (with quantity & pricing)
  ├─ Stock Entries (receipt)
  ├─ Invoices (inbound)
  └─ Purchase Returns

Ticket
  ├─ Ticket Logs (history)
  ├─ Assignments
  └─ Warranty claims

Project
  ├─ Tasks (with status FSM)
  ├─ Team Members
  ├─ Materials & Equipment
  ├─ Expenses
  └─ Project Invoices

Service
  ├─ Service Items (in contracts/orders)
  └─ Labor pricing

Lead
  ├─ Conversion history
  └─ Activity log
```

---

## Service Layer Architecture

Each module has a dedicated **Service** class implementing business logic with **Finite State Machine (FSM)** patterns:

### FSM Patterns

**Order FSM:**
```
New → Confirmed → (PartialDelivered) → Delivered → Completed
       ↘ Cancelled
```

**Stock Transfer FSM:**
```
Draft → Confirmed → Completed
        ↘ Cancelled
```

**Sales Return FSM:**
```
Draft → Confirmed → Completed
        ↘ Cancelled
```

**Lead FSM:**
```
New → Contacted → Qualified → (Converted/Lost)
      ↘ Lost
```

---

## API Architecture

### RESTful Endpoints Pattern
Each module follows standard CRUD:
- `GET /api/{module}` — List with filters, pagination
- `GET /api/{module}/{id}` — Show detail
- `POST /api/{module}` — Create new
- `PUT/PATCH /api/{module}/{id}` — Update
- `DELETE /api/{module}/{id}` — Delete (soft delete for master data)

### Response Format
```json
{
  "data": { /* resource */ },
  "message": "Success message",
  "status": "success"
}
```

### Error Handling
- 400: Bad Request (validation)
- 403: Forbidden (permission denied)
- 404: Not Found
- 500: Server Error

---

## Frontend Architecture

### Vue 3 Components
- **Index Pages:** Lists with filtering, pagination, bulk actions
- **Form Pages:** Create/edit with validation, auto-fill
- **Show Pages:** Detail view with related data, history
- **Dropdowns:** NotificationDropdown, StatusBadge, pagination controls

### State Management
- **Composables:** Reusable logic (useForm, useFilters, useNotifications)
- **Reactive Data:** Vue 3 composition API with ref/reactive
- **Props & Emits:** Component communication

### UI Components
- **StatusBadge:** Visual status indicators (Draft, Confirmed, Completed, etc.)
- **FormGroup:** Labeled inputs with validation
- **DataTable:** Sortable, filterable lists
- **DatePicker:** Date selection
- **FileUpload:** Document attachment

---

## Database Schema Summary

### Total Tables: 50+

**Core Tables:**
- users (8 columns)
- roles, role_permissions
- customers, suppliers, products, services
- categories, unit_conversions

**Transaction Tables:**
- orders, order_items
- purchase_orders, purchase_order_items
- quotations, quotation_items
- contracts, contract_items, contract_payments

**Warehouse Tables:**
- product_serials, serial_statuses
- stock_movements
- stock_entries, stock_entry_items
- stock_exits, stock_exit_items
- stock_transfers, stock_transfer_items
- stock_adjustments

**Returns & Logistics:**
- sales_returns, sales_return_items
- purchase_returns, purchase_return_items

**Projects & Support:**
- projects, project_tasks, project_members
- project_materials, project_expenses
- tickets, ticket_logs, ticket_attachments
- warranties, warranty_claims

**Accounting:**
- invoices, invoice_items, invoice_payments
- price_lists, price_list_items

**Operations:**
- leads
- notifications
- activity_log (spatie/laravel-activity)
- imports (product_imports, customer_imports, supplier_imports)

### Key Indexes
- All FKs indexed
- Unique: code columns (orders, leads, transfers, etc.)
- Composite: (customer_id, status), (product_id, warehouse_id)

---

## Security Architecture

### Authentication
- Laravel Fortify (session-based)
- CSRF protection
- Password hashing (bcrypt)

### Authorization
- Role-Based Access Control (RBAC)
- Permission checking on routes & actions
- Soft delete enforcement (archived data not visible)

### Data Protection
- Mass assignment protection ($fillable)
- SQL injection prevention (parameterized queries)
- XSS protection (Vue escaping)

### Audit Trail
- activity_log table (Spatie package)
- User action logging
- Change tracking on sensitive data

---

## Deployment Architecture

### Environment
- **Local:** Laravel Artisan, npm dev server (Vite)
- **Production:** Docker containers, VPS hosting

### Services
- **Web:** Nginx reverse proxy
- **App:** Laravel (PHP-FPM)
- **Database:** PostgreSQL
- **Cache:** Redis (optional)

### CI/CD
- GitHub Actions for testing
- Auto-deployment to VPS
- Database migration automation

---

## Performance Considerations

1. **Indexing:** All FKs and code columns indexed
2. **Query Optimization:** Eager loading (with()) in controllers
3. **Caching:** Price lists cached, notification polling debounced
4. **Pagination:** 15 items per page by default
5. **Serial Tracking:** Efficient status queries

---

## Integration Points

- **Excel Import:** maatwebsite/excel package
- **PDF Export:** Invoice & quotation PDFs
- **Notifications:** Database queuing (30s polling)
- **Audit Logging:** Spatie Activity Log package
- **File Storage:** Local/S3 for attachments

---

## Future Extensions

- Payment gateway integration (Stripe, VNPay)
- SMS notifications
- Advanced reporting (BI tools)
- Mobile app (React Native)
- Multi-language support
