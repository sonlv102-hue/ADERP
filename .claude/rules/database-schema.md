# Mini ERP — Database Schema Overview

Complete schema for 100+ tables, grouped by module. Cập nhật: 2026-06-23.

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
| customer_bank_accounts | KH bank accounts | id, customer_id, bank_name, account_number, account_name, branch, is_primary, is_active |
| supplier_opening_advances | Ứng trước NCC (331UT) | id, supplier_id, advance_date, amount, remaining_amount, account_code (='331UT'), status, journal_entry_id |
| supplier_advance_allocations | Phân bổ ứng trước NCC | id, advance_id, purchase_invoice_id, amount, allocated_at, reversal_journal_entry_id |
| customer_opening_advances | Ứng trước KH (131UT) | id, customer_id, advance_date, amount, remaining_amount, account_code (='131UT'), status, journal_entry_id |
| customer_advance_allocations | Phân bổ ứng trước KH | id, advance_id, invoice_id, amount, allocated_at |
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
| inventory_balances | AVCO rolling balance | id, product_id, warehouse_id, qty_on_hand, value_on_hand, avg_cost, last_movement_id — UNIQUE(product_id, warehouse_id) |
| stock_exit_purchase_orders | Junction: exit ↔ multi-PO | id, stock_exit_id, purchase_order_id |
| project_inventory_lots | FIFO lots cho project exit | id, product_id, project_id, warehouse_id, stock_entry_item_id, qty_remaining, unit_cost |
| stock_exit_item_lot_allocations | FIFO allocation per exit item | id, stock_exit_item_id, lot_id, quantity, unit_cost |

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
| purchase_invoice_items | Dòng hàng HĐ mua (per-line) | id, purchase_invoice_id, product_id, description, quantity, unit_price, vat_rate, vat_amount, subtotal, account_code, credit_account, project_id |
| purchase_return_items | Return line items | id, purchase_return_id, product_id, quantity |

## Projects & Tasks
| Table | Purpose | Key columns |
|---|---|---|
| projects | IT projects | id, code (DA-), name, status (planning/active/on_hold/completed/cancelled), start_date, end_date, budget, created_by |
| project_tasks | Project tasks | id, project_id, name, status (pending/in_progress/completed), priority, assigned_to |
| project_members | Team members | id, project_id, employee_id, role |
| project_materials | Materials used | id, project_id, product_id, quantity, unit_price, subtotal |
| project_expenses | Project costs | id, project_id, category, description, amount, created_by |
| project_wip_entries | WIP accounting (TK 154) | id, project_id, source_type, source_id, cost_type, amount, description, entry_date, status (active/cancelled), journal_entry_id, source_item_id, vat_amount |
| project_direct_materials | Vật tư phát sinh trực tiếp | id, project_id, product_id, product_name, quantity, unit_price, total_amount, handling_type (tracking_only\|invoice_link\|journal_entry), supplier_id, credit_account_code, purchase_invoice_item_id, journal_entry_id, status |
| project_wip_correction_logs | Nhật ký sửa WIP | id, project_id, project_wip_entry_id, correction_type, amount, journal_entry_id, created_by |
| project_extra_cost_transfers | Kết chuyển chi phí PS→154 | id, project_id, project_expense_id, transfer_date, debit_account (154), credit_account, amount, status (posted\|cancelled), journal_entry_id, project_wip_entry_id |

## Support & Tickets
| Table | Purpose | Key columns |
|---|---|---|
| tickets | Support tickets | id, code (TK-), customer_id, status (new/assigned/in_progress/resolved/closed), priority, assigned_to, created_by |
| ticket_logs | Activity records | id, ticket_id, action, user_id, old_value, new_value, created_at |
| warranties | Product warranties | id, product_id, customer_id, start_date, end_date, status (active/expired/claimed) |

## HR / Payroll
| Table | Purpose | Key columns |
|---|---|---|
| employees | Employee master | id, code, name, email, phone, department (string, no master table), position (string), status (active/probation/resigned/terminated), employment_type (full_time/part_time/contract/seasonal), national_id, national_id_issue_date/place, contract_start_date/end_date, social_insurance_no, bank_account_no, bank_name, pit_tax_code, base_salary, allowance + allowance_responsibility/lunch/phone/transport |
| attendance_sheets | Monthly attendance batch | id, code (CC-), period (YYYY-MM), status (draft/locked), notes, created_by |
| attendance_records | Per-employee per-day record | id, attendance_sheet_id, employee_id, work_date, status (present/absent/half/leave/holiday), hours_worked |
| payrolls | Monthly payroll batch | id, period (YYYY-MM), status (draft/confirmed), is_locked, total_adjustment, created_by |
| payroll_items | Per-employee payroll line | id, payroll_id, employee_id, base_salary, allowance_detail (JSON), gross_salary, bhxh/bhyt/bhtn emp+employer, pit, net_salary, adjustment_amount, adjustment_reason, taxable, cash_voucher_id, status (pending/paid) |
| pit_configs | Biểu thuế PIT động | id, period_from, period_to, brackets (JSON) |
| employee_dependents | Người phụ thuộc | id, employee_id, name, id_number, relation, from_date, to_date |

## Accounting & Invoices
| Table | Purpose | Key columns |
|---|---|---|
| account_codes | Chart of accounts | id (string code), name, type (asset/liability/equity/revenue/expense), normal_balance (debit/credit), parent_code |
| accounting_periods | Accounting periods | id, period (YYYY-MM), status (open/closed/locked) |
| journal_entries | Journal entry header | id, code (BT-), entry_date, description, status (draft/posted/reversed/voided), is_auto, reference_type, reference_id, exclude_from_period_movement, voided_at, voided_by, void_reason, edited_by_user, original_lines (jsonb), posted_at, reversed_by_id |
| journal_entry_lines | Debit/credit lines | id, journal_entry_id, account_code, debit, credit, description, project_id, partner_type, partner_id, fixed_asset_id, sort_order |
| accounting_settings | TK cấu hình được | id, key, value, label, group — dùng AccountingSettings::get(key, default) |
| period_close_batches | Kết chuyển cuối kỳ | id, period, status, journal_entry_id, closed_by, closed_at |
| balance_sheet_account_mappings | Mapping TK→chỉ tiêu B01a | id, account_code, b01_line_code, section |
| accounting_posting_jobs | Retry queue auto-posting | id, reference_type, reference_id, status, attempts, last_error |
| ar_ap_opening_balances | Số dư đầu kỳ AR/AP | id, type (ar/ap), partner_id, partner_type, amount, as_of_date |
| inventory_opening_balances | Số dư tồn kho đầu kỳ | id, product_id, warehouse_id, quantity, unit_cost, as_of_date |
| inventory_balances | AVCO rolling balance | id, product_id, warehouse_id, qty_on_hand, value_on_hand, avg_cost, last_movement_id, initialized_from — UNIQUE(product_id, warehouse_id) |
| invoices | Customer invoices | id, code (HĐ-), order_id, status (draft/sent/paid/overdue), subtotal, tax, total, advance_allocated, created_by |
| invoice_items | Dòng hàng HĐ bán | id, invoice_id, product_id, name, quantity, unit_price, vat_rate, vat_amount, subtotal, revenue_account_code |
| payments | Invoice payments | id, invoice_id, amount, payment_method, payment_date, cash_voucher_id, created_by |
| bank_accounts | Company bank accounts | id, bank_name, account_number, account_name, account_code (is_detail=true), branch, opening_balance |
| bank_transactions | Bank statement lines | id, bank_account_id, transaction_date, description, debit, credit, tx_type, supplier_bank_account_id, internal_account_id, internal_status, import_hash, status, journal_entry_id, reconciled_at |
| internal_bank_accounts | Công ty nội bộ (CK nội bộ) | id, name, account_number, bank_name, owner_name, is_active |
| cash_vouchers | Thu/chi quỹ tiền mặt | id, code (PT-/PC-), fund_id, type (receipt/payment), business_type, partner_type, partner_id, amount, description, cash_flow_code, status (draft/confirmed/cancelled) |
| funds | Quỹ tiền mặt | id, code, name, account_code, balance |
| fund_transfers | Luân chuyển quỹ (LCQ-) | id, transfer_no, transfer_date, from_fund_id, to_fund_id, amount, status (draft/posted/reversed), journal_entry_id |
| prepaid_expenses | Chi phí trả trước (CPT-) | id, code, description, total_amount, start_date, end_date, monthly_amount, status |
| prepaid_expense_allocations | Phân bổ hàng tháng | id, prepaid_expense_id, period (YYYY-MM), amount, journal_entry_id |
| payment_terms | Điều khoản thanh toán | id, name, days, discount_percent |
| fixed_asset_categories | Nhóm TSCĐ | id, name, code, useful_life_months, depreciation_account, accumulated_account, expense_account |
| fixed_assets | Fixed asset register | id, code, name, category_id, acquisition_date, acquisition_cost, useful_life_months, accumulated_depreciation, net_book_value, monthly_depreciation, last_depreciation_period, tt45_group, status (active/fully_depreciated/disposed) |
| fixed_asset_depreciations | Monthly depreciation records | id, fixed_asset_id, period (YYYY-MM), amount, accumulated_before, net_book_value_after, journal_entry_id, non_deductible_amount |
| fixed_asset_movements | Điều chuyển TSCĐ | id, fixed_asset_id, movement_date, from_location, to_location, journal_entry_id |
| fixed_asset_repairs | Sửa chữa TSCĐ | id, fixed_asset_id, repair_date, cost, description, journal_entry_id |
| fixed_asset_disposals | Thanh lý TSCĐ | id, fixed_asset_id, disposal_date, proceeds, gain_loss, journal_entry_id |

## Personal Finance
| Table | Purpose | Key columns |
|---|---|---|
| shareholders | Cổ đông (TV-) | id, code, name, id_number, ownership_percent, equity_amount, status |
| personal_loans | Vay cá nhân (PVay-) | id, code, borrower_type, borrower_id, amount, interest_rate, start_date, end_date, status, journal_entry_id |
| personal_loan_repayments | Trả nợ vay cá nhân | id, loan_id, repayment_date, principal, interest, cash_voucher_id, journal_entry_id |
| personal_expense_reports | Thanh toán chi phí cá nhân (PCH-) | id, code, employee_id, report_date, total_amount, status, journal_entry_id |
| personal_expense_lines | Dòng chi phí cá nhân | id, report_id, description, amount, account_code, receipt_date |

## CCDC — Công cụ dụng cụ
| Table | Purpose | Key columns |
|---|---|---|
| small_tool_categories | Nhóm CCDC | id, name, code, description |
| small_tools | CCDC master | id, code, name, category_id, unit, quantity, original_cost, vat_amount, total_cost, acquisition_type (stock\|direct), recognition_method (immediate\|allocation), allocation_periods, allocation_start_date, stock_account_code, expense_account_code, payable_account_code, periods_allocated, total_allocated, status (draft\|in_stock\|in_use\|allocating\|fully_allocated\|disposed\|cancelled) |
| small_tool_receipts | Nhập CCDC vào kho | id, small_tool_id, receipt_date, quantity, warehouse_id, journal_entry_id |
| small_tool_issues | Xuất CCDC | id, small_tool_id, issue_date, quantity, department, project_id, journal_entry_id |
| small_tool_allocations | Phân bổ chi phí CCDC | id, small_tool_id, period (YYYY-MM), amount, journal_entry_id |
| small_tool_transfers | Điều chuyển CCDC | id, small_tool_id, transfer_date, from_dept, to_dept, notes |
| small_tool_disposals | Thanh lý CCDC | id, small_tool_id, disposal_date, proceeds, journal_entry_id |

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
- `bank_transactions.internal_account_id` là nullable FK **không có DB-level constraint** — cẩn thận khi xóa InternalBankAccount
- `project_members.employee_id` (không phải user_id) từ migration 900039
- `inventory_balances`: UNIQUE(product_id, warehouse_id) — AVCO rolling. Non-project exit BLOCKS nếu chưa init
- `stock_exit_items.cost_source`: 'avco' | 'fifo' | 'legacy'
- `journal_entries.exclude_from_period_movement = true` → bút toán đầu kỳ, không tính vào phát sinh CĐPS
- `cash_vouchers.cash_flow_code`: nullable, dùng cho phân loại B03-DNN
- `purchase_invoices.invoice_type`: 9 loại, `PurchaseInvoiceType::defaultCreditAccount()` tự routing 3311/3312
