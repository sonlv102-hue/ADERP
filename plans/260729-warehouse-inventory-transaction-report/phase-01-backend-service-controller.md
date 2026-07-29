# Phase 01: Backend — Service, Controller, Export, Route, Permission, Tests

## Context Links
- Overview: `plans/260729-warehouse-inventory-transaction-report/plan.md`
- Reference (read-only, DO NOT modify): `app/Services/Reports/InventoryReportService.php`
- Reference: `app/Http/Controllers/Reports/InventoryReportController.php` (`stockCard` action, lines 48-177)
- Reference: `app/Services/Reports/StockMovementDetailReportService.php`
- Reference: `app/Http/Controllers/Reports/ArDetailController.php`
- Reference: `app/Exports/Reports/InventoryReportExport.php`, `StockEntryDetailExport.php`
- Rule: `.claude/rules/database-schema.md` § Warehouse & Stock
- Rule: `.claude/rules/reporting-standards.md`

## Overview
Priority: High. Status: Not started. Builds the new read-only ledger-style
report: `InventoryTransactionReportService`, `InventoryTransactionReportController`,
`InventoryTransactionReportExport`, 2 routes, MenuItem permission gate (permission
reused, no new permission), and feature tests.

## Key Insights
- `stock_movements.source_type` has exactly 7 real values in this codebase
  (verified via grep of `'source_type' =>` in every service): `App\Models\StockEntry`,
  `StockExit`, `StockTransfer`, `SalesReturn`, `PurchaseReturn`, `InventoryCount`,
  `InventoryOpeningBalance`. All must resolve to a real doc code/date — silently
  falling back to `SM-{id}` for any of these would break reconciliation with
  actual stock (explicitly forbidden by task).
- Date columns per source (verified in migrations): `stock_entries.entry_date`,
  `stock_exits.exit_date`, `stock_transfers.transfer_date` (900010),
  `sales_returns.return_date` (900013), `purchase_returns.return_date` (900016),
  `inventory_counts.count_date` (900030). `inventory_opening_balances` has no
  exact date column (only `period` YYYY-MM, 900048) → fallback `DATE(sm.created_at)`.
- `StockTransferService.php:69-92` confirms each transfer posts **2** movements
  (out-leg at `from_warehouse_id`, in-leg at `to_warehouse_id`), both with
  `amount` populated. Classifying purely by `sm.quantity` sign (not by
  `source_type`) means transfers, returns, and count adjustments all fall
  naturally into nhập/xuất columns with zero special-casing, and warehouse
  filtering naturally isolates the correct leg.
- `ACTIVE_FILTER = "(sm.status IS NULL OR sm.status = 'active')"` (migration
  `2026_06_25_900213_add_status_to_stock_movements.php`) must be applied
  everywhere — voided movements must never appear.
- Per `.claude/rules/reporting-standards.md` rule 7: lookup/dashboard reports
  need **no signature block**. This is a tra-cứu report — confirmed no
  signature section needed. PDF/print export is explicitly out of scope for
  now (only Excel), noted as future scope if requested later.
- Task requires NOT reusing/modifying `InventoryReportService.php` — so the
  opening-balance formula (`SUM(quantity) WHERE doc_date < date_from`, active
  filter only) is intentionally duplicated in the new service, trimmed to only
  what's needed (no `stock_in`/`stock_out` aggregate — those come from summing
  the raw per-movement rows already fetched for the detail list, avoiding a
  second query).

## Requirements
### Functional
- Filters: `date_from`, `date_to` (required, default = year-to-date, matching
  `InventoryReportService`'s convention — **changed post-launch 2026-07-29**:
  originally defaulted to current-month like `stockCard`, but real usage
  showed 132/133 products have zero movements in a typical current month,
  making the report look broken on first load), `warehouse_id`
  (nullable = toàn hệ thống), `product_id` (optional single-product pick via
  `RemoteSearchSelect` / `search.products`), `search` (optional text — code/name),
  `category_id` (optional).
- Output: paginated **by product**, each product = 1 group of rows:
  `[opening row, ...chronological movement rows, closing/summary row]`.
- Each movement row populates EITHER nhập columns OR xuất columns (never both),
  per the pairing decision in `plan.md`. Running qty/value balance shown on
  every row.
- Excel export mirrors UI data exactly (same service call), matching the
  `InventoryReportExport` convention of "no re-derivation of values, straight
  passthrough from the same array shape."

### Non-functional
- No new migration.
- Query bounded per page: raw per-movement fetch is scoped to only the
  product IDs on the current page (bounded by `perPage`, not the whole catalog).
- File ≤ 200 lines per `development-rules.md` — split Service (query + mapping)
  from Controller (HTTP glue) as usual.

## Architecture
```
InventoryTransactionReportController
  ├─ index()  → InventoryTransactionReportService::buildProductPage()  → Inertia::render('Reports/Warehouse/InventoryTransactions')
  └─ export() → Excel::download(InventoryTransactionReportExport)

InventoryTransactionReportService
  ├─ buildProductQuery(filters)          — product list + opening SUM subquery (paginate by product)
  ├─ buildMovementRows(productIds, filters) — raw per-movement rows for current page's products
  ├─ describeMovement(row)               — source_type → Vietnamese label (ports stockCard's match/case)
  └─ mapProductGroup(product, movements) — builds [opening, ...movement rows, closing] w/ running balance

InventoryTransactionReportExport (FromCollection/WithHeadings/WithMapping/WithStyles/WithTitle)
  └─ collection() → InventoryTransactionReportService::buildAllProductGroups(filters)->flatten()
```

### Row shape (14 columns, keys map 1:1 to mockup)
```php
[
  'row_type'     => 'opening'|'movement'|'closing',
  'product_code' => string,
  'description'  => string,   // "Tồn đầu kỳ" | "Nhập kho NK-xxxx" | "Xuất kho XK-xxxx" |
                                // "Chuyển kho đến/từ CK-xxxx" | "Trả hàng bán TH-xxxx" |
                                // "Trả hàng mua THM-xxxx" | "Điều chỉnh kiểm kê IK-xxxx" |
                                // "Cộng phát sinh + Tồn cuối kỳ"
  'qty_begin'    => ?float, 'value_begin' => ?float,   // opening row only
  'in_doc_code'  => ?string, 'in_doc_date' => ?string, 'qty_in'  => ?float, 'value_in'  => ?float,
  'out_doc_code' => ?string, 'out_doc_date'=> ?string, 'qty_out' => ?float, 'value_out' => ?float,
  'qty_end'      => ?float, 'value_end'   => ?float,   // running balance after this row
]
```

### SQL joins (extends the `InventoryReportService::JOINS`/`DOC_DATE` pattern)
```php
LEFT JOIN stock_entries se   ON sm.source_id = se.id AND sm.source_type = 'App\\Models\\StockEntry'
LEFT JOIN stock_exits sx     ON sm.source_id = sx.id AND sm.source_type = 'App\\Models\\StockExit'
LEFT JOIN stock_transfers st ON sm.source_id = st.id AND sm.source_type = 'App\\Models\\StockTransfer'
LEFT JOIN sales_returns sr   ON sm.source_id = sr.id AND sm.source_type = 'App\\Models\\SalesReturn'
LEFT JOIN purchase_returns pr ON sm.source_id = pr.id AND sm.source_type = 'App\\Models\\PurchaseReturn'
LEFT JOIN inventory_counts ic ON sm.source_id = ic.id AND sm.source_type = 'App\\Models\\InventoryCount'
-- InventoryOpeningBalance has no code column → COALESCE fallback below

DOC_DATE = COALESCE(se.entry_date, sx.exit_date, st.transfer_date, sr.return_date, pr.return_date, ic.count_date, DATE(sm.created_at))
DOC_CODE = COALESCE(se.code, sx.code, st.code, sr.code, pr.code, ic.code, CONCAT('DK-', sm.id))
```

## Related Code Files
### Create
- `app/Services/Reports/InventoryTransactionReportService.php`
- `app/Http/Controllers/Reports/InventoryTransactionReportController.php`
- `app/Exports/Reports/InventoryTransactionReportExport.php`
- `tests/Feature/Reports/InventoryTransactionReportTest.php`

### Modify
- `routes/web.php` — add 2 routes inside existing `reports.` group (~line 855, right after `reports.stock_card`):
  ```php
  Route::get('inventory-transactions',        [InventoryTransactionReportController::class, 'index'])->name('inventory_transactions');
  Route::get('inventory-transactions/export', [InventoryTransactionReportController::class, 'export'])->name('inventory_transactions.export');
  ```
  (Inherits `middleware('can:reports.view')` from the group — no new middleware needed; `warehouse` role already has `reports.view`, verified `RolePermissionSeeder.php:271`.)

### Do Not Touch
- `app/Services/Reports/InventoryReportService.php`
- `app/Http/Controllers/Reports/InventoryReportController.php`

## Implementation Steps
1. Create `InventoryTransactionReportService` with the extended JOINS/DOC_DATE/DOC_CODE
   consts above, `ACTIVE_FILTER` copied verbatim from `InventoryReportService.php:22`.
2. `buildProductQuery(array $filters): Builder` — products list with a
   `leftJoinSub` opening-balance subquery (`SUM(quantity) WHERE doc_date < date_from`,
   `SUM(amount) WHERE doc_date < date_from`, same shape as
   `InventoryReportService::buildSmAggregate` lines 44-53 but trimmed to just
   the "begin" SUMs); apply `search`/`category_id`/`product_id` filters;
   `orderBy('products.code')`.
3. `buildProductPage(array $filters, int $perPage = 15): LengthAwarePaginator` —
   paginate step 2's query, then for the current page's product IDs, run one
   query fetching all active `stock_movements` in `[date_from, date_to]` with
   doc code/date/description, `orderBy(product_id, doc_date, sm.id)`. Group in
   PHP by `product_id`, build `[opening, ...movements, closing]` per product
   with a running `$qty`/`$value` accumulator seeded from the opening balance.
4. `buildAllProductGroups(array $filters): Collection` — same as step 3 without
   pagination, for Excel export.
5. `private function describeMovement(object $row): string` — port the
   `match($refType)` block from `InventoryReportController.php:141-151`
   (StockEntry/StockExit/StockTransfer/SalesReturn/PurchaseReturn/
   InventoryCount/InventoryOpeningBalance/default), adapted to use the new
   joined `doc_code` instead of the raw `reference_id` (fixes the latent bug
   noted in `stockCard` where `StockTransfer`/returns/counts use
   `reference_id` — the movement's own DB id — instead of the real document
   code; new service must use the actual joined code).
6. Create `InventoryTransactionReportController` (`index`, `export`) mirroring
   `InventoryReportController.php:19-46` structure: default
   `date_from = now()->startOfMonth()`, `date_to = now()`; pass `warehouses`,
   `categories` lookups; `Inertia::render('Reports/Warehouse/InventoryTransactions', ...)`.
7. Create `InventoryTransactionReportExport` mirroring `StockEntryDetailExport.php`
   (`FromCollection`/`WithHeadings`/`WithMapping`/`WithStyles`/`WithTitle`),
   14 Vietnamese headings matching the row shape above; bold row 1 only (no
   special per-row styling needed for v1 — YAGNI).
8. Add the 2 routes to `routes/web.php` (see Related Code Files).
9. Write feature tests (see Todo List) mirroring
   `tests/Feature/Reports/InventoryReportConsistencyTest.php` conventions
   (`RefreshDatabase`, `Gate::before` bypass for functional tests, a separate
   un-bypassed test for the permission check).

## Todo List
- [ ] `InventoryTransactionReportService` created, ≤ 200 lines (split mapping helpers into a 2nd file if it grows past that)
- [ ] `buildProductQuery` / `buildProductPage` / `buildAllProductGroups` implemented
- [ ] `describeMovement` covers all 7 `source_type` values with correct joined doc code (not raw `reference_id`)
- [ ] Controller `index`/`export` created
- [ ] Export class created
- [ ] Routes added inside existing `reports.` group
- [ ] Test: opening balance excludes movements on/after `date_from` (mirrors `InventoryReportConsistencyTest` TC4)
- [ ] Test: a nhập movement row has xuất columns null and vice versa (core pairing assertion)
- [ ] Test: closing row `qty_end`/`value_end` = opening + Σqty_in − Σqty_out (reconciles with example in `plan.md`)
- [ ] Test: `status = 'voided'` movement excluded from rows and from opening balance
- [ ] Test: `warehouse_id` filter narrows movements to that warehouse only (transfer leg isolation)
- [ ] Test: `StockTransfer` movement appears as a normal nhập or xuất row (not dropped, not mislabeled)
- [ ] Test: route returns 403 without `reports.view`; 200 with it
- [ ] `php artisan test --filter=InventoryTransactionReportTest` passes
- [ ] `php artisan route:list` shows both new routes under `reports.`

## Success Criteria
- Report reconciles: for any product/period, opening + Σnhập − Σxuất = closing,
  matching `InventoryReportService`'s aggregate totals for the same filters
  (cross-check test recommended: compare closing qty from new service vs
  `stock_end` from `InventoryReportService::mapRow` for same product/period).
- No `stock_movements` row for any product in the filtered period is missing
  from the detail list (transfers/returns/counts all appear).
- All tests pass; no changes to `InventoryReportService.php`/`InventoryReportController.php`.

## Risk Assessment
- **Query cost without a product filter over a long date range**: mitigated by
  paginating at the product level (default 15/page) so the per-movement query
  is always bounded to ≤15 products' worth of rows; Excel export has no such
  bound — document as a known limitation, recommend users filter by
  `product_id` or a shorter range for full-catalog exports (not hard-enforced
  per task instructions; revisit with a warning banner if it proves slow in practice).
- **Duplicated ACTIVE_FILTER/JOINS logic** vs `InventoryReportService.php`
  (intentional, per task constraint not to touch that file) — if
  `stock_movements`/`stock_entries` schema changes, both places need updating.
  Flag for a future refactor into a shared trait/concern once the team is
  comfortable touching the aggregate report too.
- **`InventoryOpeningBalance` movements dated inside the queried period**
  (rare/edge case — normally dated at/before period start) will appear as a
  normal nhập row rather than being suppressed; documented as acceptable
  since it's transparent, not a silent drop.

## Security Considerations
- Route gated by `can:reports.view` (existing middleware group) — no new
  permission introduced, per task instruction to reuse `warehouse.view`/`reports.view`.
- No per-warehouse ownership restriction needed — all warehouse data is
  already org-wide visible under `warehouse.view`, consistent with sibling
  reports (`reports.inventory`, `reports.stock_card`).
- Read-only report — no state mutation, no CSRF-sensitive actions.

## Next Steps
- Phase 2 consumes `InventoryTransactionReportController@index`'s Inertia
  props (`rows` paginator of product groups, `filters`, `warehouses`,
  `categories`) to build the Vue page and sidebar entry.
