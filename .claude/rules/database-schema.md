# Mini ERP — Database Schema Overview

Complete schema for 50+ tables, grouped by module.

## Auth & Core
| Table | Purpose | Key columns |
|---|---|---|
| users | User accounts | id, email, password, role_id, is_active |
| roles | Permission roles | id, name (admin/director/sales/warehouse/technical/accounting/cskh) |
| permissions | Action permissions | id, name, description |
| model_has_roles | User → Role | model_id, model_type, role_id |
| model_has_permissions | User → Permission | model_id, model_type, permission_id |
| role_has_permissions | Role → Permission | role_id, permission_id |

## CRM
| Table | Purpose | Key columns |
|---|---|---|
| customers | Client companies | id, code (KH-), name, phone, email, address, is_active |
| suppliers | Vendor companies | id, code (NCC-), name, phone, email, address, is_active |
| leads | Sales prospects | id, code (LD-), name, source, status (New/Contacted/Qualified/Lost/Converted), converted_customer_id |

## Catalog & Pricing
| Table | Purpose | Key columns |
|---|---|---|
| product_categories | Product groups | id, name, description |
| products | Physical products | id, code (SP-), name, category_id, cost_price, business_cost, unit_id, is_active |
| services | Intangible services | id, code (DV-), name, cost_price, business_cost, is_active |
| product_serials | Serial numbers | id, product_id, serial_number, status (InStock/Sold/ReturnedToSupplier/Cancelled), warehouse_id, current_owner_id |
| price_lists | Pricing tiers | id, code, name, is_active |
| price_list_items | Pricing rules | id, price_list_id, product_id/service_id, unit_price |

## Warehouse & Stock
| Table | Purpose | Key columns |
|---|---|---|
| warehouses | Storage locations | id, code, name, address, manager_id |
| stock_movements | Stock transactions | id, warehouse_id, product_id, quantity, movement_type (in/out), reference_type, reference_id, created_at |
| stock_entries | Inbound shipments | id, code (NK-), warehouse_id, purchase_order_id, status (draft/confirmed/cancelled), total_quantity, created_by |
| stock_entry_items | Entry line items | id, stock_entry_id, product_id, quantity, cost_price, subtotal |
| stock_exits | Outbound shipments | id, code (XK-), warehouse_id, order_id, status (draft/confirmed/cancelled), total_quantity, created_by |
| stock_exit_items | Exit line items | id, stock_exit_id, product_id, quantity, unit_price, subtotal |
| stock_transfers | Warehouse transfers | id, code (CK-), from_warehouse_id, to_warehouse_id, status (draft/confirmed/cancelled), created_by |
| stock_transfer_items | Transfer line items | id, stock_transfer_id, product_id, quantity, unit_price |
| inventory_counts | Stocktaking sessions | id, code (IK-), warehouse_id, count_date, status (draft/confirmed/cancelled), counted_by |
| inventory_count_items | Count line items | id, inventory_count_id, product_id, system_quantity (snapshot), counted_quantity, notes |

## Sales
| Table | Purpose | Key columns |
|---|---|---|
| quotations | Sales proposals | id, code (BG-), customer_id, status (draft/sent/approved/rejected), subtotal, tax, total, created_by |
| quotation_items | Quote line items | id, quotation_id, product_id/service_id, name (snapshot), quantity, unit_price, subtotal |
| orders | Sales orders | id, code (DH-), customer_id, status (pending/confirmed/partial_delivered/delivered), delivered_quantity, subtotal, tax, total, created_by |
| order_items | Order line items | id, order_id, product_id/service_id, name (snapshot), quantity, delivered_quantity, unit_price, subtotal |
| contracts | Sales contracts | id, code (HD-), order_id, customer_id, status (draft/signed), value, created_by |
| sales_returns | Return documents | id, code (TH-), customer_id, status (draft/confirmed), created_by |
| sales_return_items | Return line items | id, sales_return_id, product_id, quantity |
| commissions | Sales commissions | id, sales_id, order_id, percentage, amount, status |

## Purchasing
| Table | Purpose | Key columns |
|---|---|---|
| purchase_orders | Vendor orders | id, code (MH-), supplier_id, status (draft/confirmed/partial_received/received), subtotal, tax, total, created_by |
| purchase_order_items | PO line items | id, purchase_order_id, product_id, quantity, received_quantity, unit_price, subtotal |
| purchase_contracts | Supplier contracts | id, supplier_id, status (draft/signed), terms, created_by |
| purchase_invoices | Vendor invoices | id, supplier_id, status (pending/received/reviewing/valid/partial_paid/paid), subtotal, tax, total |
| purchase_invoice_payments | Vendor payments | id, purchase_invoice_id, amount, payment_date, method |
| purchase_returns | Return to vendor | id, code (THM-), supplier_id, status (draft/confirmed), created_by |
| purchase_return_items | Return line items | id, purchase_return_id, product_id, quantity |

## Projects & Tasks
| Table | Purpose | Key columns |
|---|---|---|
| projects | IT projects | id, code (DA-), name, status (planning/active/on_hold/completed/cancelled), start_date, end_date, budget, created_by |
| project_tasks | Project tasks | id, project_id, name, status (pending/in_progress/completed), priority, assigned_to |
| project_members | Team members | id, project_id, user_id, role |
| project_materials | Materials used | id, project_id, product_id, quantity, unit_price, subtotal |
| project_expenses | Project costs | id, project_id, category, description, amount, created_by |

## Support & Tickets
| Table | Purpose | Key columns |
|---|---|---|
| tickets | Support tickets | id, code (TK-), customer_id, status (new/assigned/in_progress/resolved/closed), priority, assigned_to, created_by |
| ticket_logs | Activity records | id, ticket_id, action, user_id, old_value, new_value, created_at |
| warranties | Product warranties | id, product_id, customer_id, start_date, end_date, status (active/expired/claimed) |

## Accounting & Invoices
| Table | Purpose | Key columns |
|---|---|---|
| invoices | Customer invoices | id, code (HĐ-), order_id, status (draft/sent/paid/overdue), amount_due, created_by |
| payments | Invoice payments | id, invoice_id, amount, payment_method, payment_date, created_by |
| fixed_assets | Fixed asset register | id, code, name, category, acquisition_date, acquisition_cost, useful_life_months, accumulated_depreciation, net_book_value, monthly_depreciation, last_depreciation_period, status (active/fully_depreciated/disposed) |
| fixed_asset_depreciations | Monthly depreciation records | id, fixed_asset_id, period (YYYY-MM), amount, accumulated_before, net_book_value_after |

## System
| Table | Purpose | Key columns |
|---|---|---|
| document_types | Doc templates | id, code (CT-), name, template, created_by |
| documents | Generated docs | id, document_type_id, reference_type, reference_id, file_path |
| settings | Config | id, key, value |
| notifications | User alerts | id, user_id, type (LowStock/TicketCreated/InvoiceOverdue), title, message, is_read |
| activity_log | Audit log | id, user_id, subject_type, subject_id, action, properties, created_at |

## Notes
- All tables have: `id`, `created_at`, `updated_at` (except reference tables)
- Master data tables use `SoftDeletes`: users, customers, suppliers, products, services, warehouses
- Enums stored as `string` type in DB, cast via Model `casts()` method
- Foreign keys named `{table_singular}_id`
- Timestamps use `timestampsTz()` for UTC
