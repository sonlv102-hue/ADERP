# Mini ERP — Database Schema Overview

Complete schema for 60+ tables, grouped by module. Cập nhật: 2026-06-05.

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
| supplier_bank_accounts | NCC bank accounts | id, supplier_id, bank_name, account_number, account_name, branch, is_primary, is_active |
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
| stock_movements | Stock transactions | id, warehouse_id, product_id, quantity, **type** (in/out), **source_type**, **source_id** (morphs), created_by, notes, created_at |
| stock_entries | Inbound shipments | id, code (NK-), warehouse_id, purchase_order_id, status (draft/confirmed/cancelled), total_quantity, created_by |
| stock_entry_items | Entry line items | id, stock_entry_id, product_id, quantity, unit_price, tax_rate (default 10%), cost_price, subtotal |
| stock_exits | Outbound shipments | id, code (XK-), warehouse_id, order_id, project_id, project_wip_entry_id, status (draft/confirmed/cancelled), total_quantity, created_by |
| stock_exit_items | Exit line items | id, stock_exit_id, product_id, quantity, unit_price, subtotal |
| stock_transfers | Warehouse transfers | id, code (CK-), from_warehouse_id, to_warehouse_id, status (draft/confirmed/cancelled), created_by |
| stock_transfer_items | Transfer line items | id, stock_transfer_id, product_id, quantity, unit_price |
| inventory_counts | Stocktaking sessions | id, code (IK-), warehouse_id, count_date, status (draft/confirmed/cancelled), counted_by |
| inventory_count_items | Count line items | id, inventory_count_id, product_id, system_quantity (snapshot), counted_quantity, notes |

## Sales
| Table | Purpose | Key columns |
|---|---|---|
| quotations | Sales proposals | id, code (BG-), customer_id, status (draft/sent/approved/rejected), subtotal, tax, total, created_by |
| quotation_items | Quote line items | id, quotation_id, product_id/service_id, name (snapshot), quantity, unit_price, subtotal, vat_rate |
| orders | Sales orders | id, code (DH-), customer_id, status (pending/confirmed/partial_delivered/delivered), delivered_quantity, subtotal, tax, total, created_by |
| order_items | Order line items | id, order_id, product_id/service_id, name (snapshot), quantity, delivered_quantity, unit_price, subtotal, vat_rate |
| contracts | Sales contracts | id, code (HD-), order_id, customer_id, status (draft/signed), value, created_by |
| sales_returns | Return documents | id, code (TH-), customer_id, status (draft/confirmed), created_by |
| sales_return_items | Return line items | id, sales_return_id, product_id, quantity |
| commissions | Sales commissions | id, sales_id, order_id, percentage, amount, status |

## Purchasing
| Table | Purpose | Key columns |
|---|---|---|
| purchase_orders | Vendor orders | id, code (MH-), supplier_id, project_id, order_id, status (draft/confirmed/partial_received/received), subtotal, tax, total, created_by |
| purchase_order_items | PO line items | id, purchase_order_id, product_id, quantity, received_quantity, unit_price, subtotal, vat_rate |
| purchase_contracts | Supplier contracts | id, supplier_id, status (draft/signed), terms, created_by |
| purchase_contract_payment_schedules | Payment schedule | id, purchase_contract_id, due_date, amount, status (pending/paid) |
| purchase_invoices | Vendor invoices | id, supplier_id, status (pending/received/reviewing/valid/partial_paid/paid), subtotal, tax, total |
| purchase_invoice_payments | Vendor payments | id, purchase_invoice_id, amount, payment_date, method |
| purchase_returns | Return to vendor | id, code (THM-), supplier_id, status (draft/confirmed), created_by |
| purchase_return_items | Return line items | id, purchase_return_id, product_id, quantity |

## Projects & Tasks
| Table | Purpose | Key columns |
|---|---|---|
| projects | IT projects | id, code (DA-), name, status (planning/active/on_hold/completed/cancelled), start_date, end_date, budget, created_by |
| project_tasks | Project tasks | id, project_id, name, status (pending/in_progress/completed), priority, assigned_to |
| project_members | Team members | id, project_id, employee_id, role |
| project_materials | Materials used | id, project_id, product_id, quantity, unit_price, subtotal |
| project_expenses | Project costs | id, project_id, category, description, amount, created_by |
| project_wip_entries | WIP accounting (TK 154) | id, project_id, source_type, source_id, cost_type (material/labor/subcontract/overhead/other), amount, description, entry_date, journal_entry_id |

## Support & Tickets
| Table | Purpose | Key columns |
|---|---|---|
| tickets | Support tickets | id, code (TK-), customer_id, status (new/assigned/in_progress/resolved/closed), priority, assigned_to, created_by |
| ticket_logs | Activity records | id, ticket_id, action, user_id, old_value, new_value, created_at |
| warranties | Product warranties | id, product_id, customer_id, start_date, end_date, status (active/expired/claimed) |

## HR / Payroll
| Table | Purpose | Key columns |
|---|---|---|
| employees | Employee master | id, code, name, email, phone, department, position, allowance_breakdown (JSON), status (active/inactive/terminated) |
| attendance_sheets | Monthly attendance batch | id, code (CC-), period (YYYY-MM), status (draft/locked), notes, created_by |
| attendance_records | Per-employee per-day record | id, attendance_sheet_id, employee_id, work_date, status (present/absent/half/leave/holiday), hours_worked |
| payrolls | Monthly payroll batch | id, period (YYYY-MM), status (draft/confirmed), is_locked, created_by |
| payroll_items | Per-employee payroll line | id, payroll_id, employee_id, base_salary, allowance_detail (JSON), gross_salary, insurance_employee, insurance_employer, pit, net_salary, status (pending/paid) |

## Accounting & Invoices
| Table | Purpose | Key columns |
|---|---|---|
| account_codes | Chart of accounts | id (string code), name, type (asset/liability/equity/revenue/expense), normal_balance (debit/credit), parent_code |
| accounting_periods | Accounting periods | id, period (YYYY-MM), status (open/closed/locked) |
| journal_entries | Journal entry header | id, code (BT-), entry_date, description, status (draft/posted/reversed), is_auto, reference_type, reference_id, posted_at, reversed_by_id |
| journal_entry_lines | Debit/credit lines | id, journal_entry_id, account_code, debit, credit, description, project_id, sort_order |
| invoices | Customer invoices | id, code (HĐ-), order_id, status (draft/sent/paid/overdue), amount_due, created_by |
| payments | Invoice payments | id, invoice_id, amount, payment_method, payment_date, created_by |
| bank_accounts | Company bank accounts | id, bank_name, account_number, account_name, branch, opening_balance |
| bank_transactions | Bank statement lines | id, bank_account_id, transaction_date, value_date, description, reference, debit, credit, running_balance, counterpart_bank, counterpart_account, counterpart_name, tx_type (supplier_payment/internal_transfer/customer_receipt/other/unknown), supplier_bank_account_id, internal_account_id, alert_note, internal_status (pending/docs_done/needs_return/returned), internal_note, return_amount, status, journal_entry_id, reconciled_at, reconciled_by, import_batch, import_hash |
| internal_bank_accounts | Công ty nội bộ (phân loại CK nội bộ) | id, name, account_number, bank_name, owner_name, description, is_active |
| cash_vouchers | Thu/chi quỹ tiền mặt | id, code (PT-/PC-), fund_id, type (receipt/payment), amount, description, status (draft/confirmed/cancelled) |
| funds | Quỹ tiền mặt | id, code, name, balance |
| prepaid_expenses | Chi phí trả trước (CPT-) | id, code, description, total_amount, start_date, end_date, monthly_amount, status |
| prepaid_expense_allocations | Phân bổ hàng tháng | id, prepaid_expense_id, period (YYYY-MM), amount, journal_entry_id |
| payment_terms | Điều khoản thanh toán | id, name, days, discount_percent |
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
- `bank_transactions.supplier_bank_account_id` và `internal_account_id` là nullable FK nhưng **chưa có DB-level constraint** (chỉ app-level) — cẩn thận khi xóa SupplierBankAccount/InternalBankAccount
- `project_members.employee_id` (không phải user_id) từ migration 900039
