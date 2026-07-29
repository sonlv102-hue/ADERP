# Phase 02: Frontend — Vue Page, Sidebar Menu, Excel Export Button

## Context Links
- Overview: `plans/260729-warehouse-inventory-transaction-report/plan.md`
- Depends on: `phase-01-backend-service-controller.md` (Controller prop shape)
- Rule: `.claude/rules/ui-style-guide.md` (mandatory read before any Vue edit)
- Reference (pattern, but has legacy non-compliant styling — see Key Insights): `resources/js/Pages/Reports/Warehouse/StockEntryDetails.vue`
- Reference: `resources/js/Pages/Reports/Inventory/Index.vue`, `StockCard.vue`
- Reference: `database/seeders/RolePermissionSeeder.php` (MenuItem seeding, lines 384, 429-438)

## Overview
Priority: High. Status: Not started (blocked on Phase 1). Builds the Index
page rendering grouped opening/movement/closing rows per product, filters
(date range, warehouse, optional single-product `RemoteSearchSelect`, text
search), Excel export button, and the **Kho** sidebar entry.

## Key Insights
- Menu is **database-driven**, not a JS config file — `page.props.menuItems`
  comes from `App\Models\MenuItem` (seeded in `RolePermissionSeeder.php`,
  shared via `HandleInertiaRequests.php:28-59`). Adding a sidebar item means
  adding one `MenuItem::create([...])` line to the seeder and re-running it —
  there is no `Components/Layout/*.js` nav config to edit.
- `RolePermissionSeeder.php:429-438` already has 3 sibling report links inside
  the `warehouse.*` group whose routes live in the `reports.*` namespace
  (`reports.stock_entry_details`, `reports.stock_exit_details`, `reports.inventory`,
  `reports.stock_card`) — confirms sidebar placement is independent of route
  prefix; this new item follows the exact same convention.
- `StockEntryDetails.vue` (closest sibling page) uses legacy raw-Tailwind
  (`form-input`, a hardcoded green `<a>` button) that **predates**
  `ui-style-guide.md` — it is **not** a styling pattern to copy. Use
  `erp-input`/`erp-btn-secondary`/`erp-btn-primary` per the style guide instead;
  only its data-flow shape (`reactive filters` + `router.get(..., {preserveState:true})`
  + `exportUrl` computed from `URLSearchParams`) is worth reusing.
- Table Standard (`ui-style-guide.md` § 7) expects a flat `<tbody>` — this
  report needs **grouped** rows (opening/movement/closing per product). Solve
  by rendering one `<tbody>` per product group with `v-for` over
  `rows.data` (array of product groups, each with a nested `rows` array from
  Phase 1), keeping the same `overflow-x-auto`/`min-w-full` wrapper and cell
  classes from the standard, but adding `font-semibold bg-slate-50` to
  opening/closing rows for visual grouping (no new component needed — plain
  Tailwind class additions within the existing table pattern, not a new pattern).
- Per `reporting-standards.md` rule 7, this is a lookup report — **no
  `<ReportSignatureSection>`**, no signer fields passed from the controller.
- `search.products` (`RemoteSearchSelect`) already exists per `ui-style-guide.md`
  § 9 — no new search endpoint needed for the optional single-product filter.
- `warehouse_id`/`category_id` are small, static, ≤30-item dropdowns → per
  style guide § 6 table, use plain `<select class="erp-input">`, not
  `RemoteSearchSelect`/`SearchableSelect`.

## Requirements
### Functional
- Filters row: Từ ngày / Đến ngày (required, native `<input type="date">`),
  Kho (`<select>`, "Tất cả kho" default), Sản phẩm (`RemoteSearchSelect`,
  optional — narrows to 1 product group), Danh mục (`<select>`, optional),
  Tìm kiếm (text, optional — code/name).
- Grouped table: per product group — bold opening row, plain movement rows
  (nhập columns OR xuất columns filled, never both — visually blank cells for
  the unused side), bold closing/summary row. 14 columns matching mockup order.
- Pagination by product group (`Pagination.vue`, `rows.links`/`rows.meta` from
  Phase 1's `LengthAwarePaginator`).
- "Xuất Excel" button (`erp-btn-secondary`, `ArrowDownTrayIcon` per icon
  table) linking to `reports.inventory_transactions.export` with current filters.
- Empty state row when no product group matches filters.

### Non-functional
- Mobile: filters use `grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3`;
  table wrapper `overflow-x-auto`; test at 375px per style guide § 11.
- No signature block, no print/PDF button (out of scope per Phase 1 decision).

## Architecture
```
Reports/Warehouse/InventoryTransactions.vue
  props: { rows: Paginator<ProductGroup>, filters, warehouses, categories }
  ProductGroup = { product: {code, name, unit}, rows: DetailRow[14-col], totals: {...} }

  <template>
    erp-page-header ("Sổ chi tiết Nhập-Xuất-Tồn" + Xuất Excel button)
    filters grid (date_from, date_to, warehouse_id, product_id via RemoteSearchSelect, category_id, search)
    table wrapper
      <template v-for="group in rows.data">
        <tbody>
          <tr class="opening row (bold)">...</tr>
          <tr v-for="r in group.rows" class="movement row">...</tr>
          <tr class="closing row (bold, bg-slate-50)">...</tr>
        </tbody>
      </template>
    Pagination
  </template>
```

## Related Code Files
### Create
- `resources/js/Pages/Reports/Warehouse/InventoryTransactions.vue`

### Modify
- `database/seeders/RolePermissionSeeder.php` — add 1 line after the existing
  `warehouse.card` entry (~line 438):
  ```php
  MenuItem::create(['parent_id' => $warehouseGroup->id, 'key' => 'warehouse.report.transactions', 'label' => 'Nhập-Xuất-Tồn chi tiết', 'route_name' => 'reports.inventory_transactions', 'icon' => 'document-text', 'required_permission' => 'warehouse.view', 'order' => 11]);
  ```
  Then run `php artisan db:seed --class=RolePermissionSeeder` (idempotent —
  seeder does `MenuItem::truncate()` then rebuilds the full tree; does not
  touch `permissions`/`roles` tables in a destructive way beyond `sync()`).
  **NOTE: this reseed is a DB-affecting command — confirm with user before
  running on any environment with real/demo data they care about, per
  CLAUDE.md dangerous-commands caution even though `db:seed` for this specific
  class is idempotent by design.**

### Read only (patterns)
- `resources/js/Pages/Reports/Inventory/Index.vue` (filters layout)
- `resources/js/Components/Shared/{RemoteSearchSelect,Pagination}.vue`
- `resources/js/composables/useCurrency.js` (`formatVnd`)

## Implementation Steps
1. Read `ui-style-guide.md` § 6, 7, 10 again immediately before writing the
   Vue file (per `CLAUDE.md` rule 1).
2. Scaffold `InventoryTransactions.vue` using the Page Layout Standard
   (`erp-page-header`, filters grid, table wrapper, `Pagination`).
3. Filters: `reactive` local state seeded from `props.filters`, `applyFilters()`
   → `router.get(route('reports.inventory_transactions'), {...}, {preserveState:true, replace:true})`
   on `@change`/`@keyup.enter`, mirroring `StockEntryDetails.vue`'s data-flow
   (not its styling).
4. Product filter: `FormField` + `RemoteSearchSelect` bound to `filters.product_id`,
   `search-url="route('search.products')"`, `display-text` pre-filled if a
   product was already selected (edit/reload case per style guide § 9).
5. Render table: outer `<div class="bg-white rounded-xl border border-gray-200 overflow-x-auto"><table class="min-w-full text-sm">` with a single `<thead>` (14 headers), then loop `rows.data` rendering one `<tbody>` per product group so the browser keeps zebra/hover scoped per group; opening/closing rows get `class="font-semibold bg-slate-50"`.
6. Blank-cell rendering: for movement rows, render `—` or empty string for
   the unused side's 4 cells (never render `0` — that would misread as "0
   quantity nhập" instead of "not applicable").
7. `formatVnd`/qty formatting via `useCurrency` composable, consistent with
   `Reports/Inventory/Index.vue`.
8. Export button: `<a :href="exportUrl" class="erp-btn-secondary">` with
   `ArrowDownTrayIcon` (`w-4 h-4`, per icon table), `exportUrl` computed the
   same way as `StockEntryDetails.vue:94-101` (URLSearchParams from filters).
9. Add the `MenuItem::create(...)` line to `RolePermissionSeeder.php` and
   re-seed (ask user first per note above).
10. Manually verify: log in as `kho@minierp.local` / `Demo@123` (has
    `warehouse` role → `warehouse.view` + `reports.view`), confirm "Kho" →
    "Nhập-Xuất-Tồn chi tiết" appears and loads.

## Todo List
- [ ] `InventoryTransactions.vue` created per Table/Page Layout Standard (no raw Tailwind buttons/inputs)
- [ ] Filters: date_from/date_to/warehouse_id/product_id/category_id/search all wired to `applyFilters()`
- [ ] `RemoteSearchSelect` used for product filter (not a preloaded `<select>`)
- [ ] Grouped table renders opening (bold) / movement / closing (bold) rows correctly, blank cells (not `0`) for unused nhập/xuất side
- [ ] Empty state row with correct colspan
- [ ] Excel export button uses `erp-btn-secondary` + icon, links to `.export` route with current filters
- [ ] `MenuItem::create(...)` added to `RolePermissionSeeder.php`, seeder re-run (with user confirmation)
- [ ] Sidebar shows "Kho" → "Nhập-Xuất-Tồn chi tiết" for `warehouse`/`admin` roles, hidden for roles without `warehouse.view`
- [ ] Tested at 375px viewport
- [ ] `npm run build` passes

## Success Criteria
- Page renders correctly for a product with unequal nhập/xuất counts (per
  `plan.md`'s worked example) — no fabricated paired rows, no dropped movements.
- Menu entry visible only to users with `warehouse.view`; route itself gated
  by `reports.view` (both already granted together to `warehouse`/`admin`/`accounting` roles).
- Passes `ui-style-guide.md` § 13 pre-merge checklist.

## Risk Assessment
- **Visual density**: 14 columns + grouped headers may feel cramped on
  laptop widths — mitigated by `overflow-x-auto` (horizontal scroll is
  acceptable per style guide, same as other wide report tables).
- **MenuItem seeder re-run risk**: `MenuItem::truncate()` at the top of that
  seeder section rebuilds the *entire* menu tree from the single source file —
  if the seeder file has drifted from what's actually in the DB (manual DB
  edits), re-running will overwrite those. Verify no other pending MenuItem
  DB changes exist before re-seeding (standard practice already required for
  any menu change in this project).

## Security Considerations
- No client-side authorization logic needed beyond existing `usePermission`
  gating already applied globally via `menuItems` filtering server-side
  (`HandleInertiaRequests.php:34-47`) — unauthorized users never receive the
  menu item or route access (double-gated: menu visibility + route middleware).

## Next Steps
- After both phases ship, consider a follow-up plan for PDF/print export if
  requested (currently intentionally out of scope per `reporting-standards.md`
  rule 7 — lookup reports don't need it).
- Consider extracting the grouped-table pattern into a reusable component if
  a third "ledger-style" report is requested later (YAGNI for now — only 1
  usage today).
