# Phase 06 — Price Lists (Bảng giá)

## Context Links
- `app/Http/Controllers/Catalog/ProductController.php` — pattern.
- `resources/js/Pages/Sales/Quotations/Form.vue`, `resources/js/Pages/Sales/Orders/Form.vue` — integration points.

## Overview
- **Priority:** P2
- **Status:** Completed
- Create reusable price lists. Quotation/Order forms gain a price-list selector that auto-fills `unit_price` for selected products.

## Key Insights
- Two-table design: `price_lists` (header) + `price_list_items` (product → unit_price).
- Default flag `is_default` (only one active default at a time — enforce in service or boot).
- Validity window (`valid_from`, `valid_to`) — UI hints only in V1; do not auto-expire.
- Auto-fill behavior: when user selects a price list in Quotation/Order form, products already on the line get re-priced; products added later also lookup the list. Manual override allowed (just sets the price field; no lock).

## Requirements
- CRUD price lists + nested items management on Show/Edit page.
- Code prefix `BG-`.
- Permissions: `price-lists.view`, `.create`, `.edit`, `.delete`.
- Inertia partial endpoint `priceListItems(PriceList $list)` returns `{ product_id: unit_price }` map for client lookup.
- Integration: Quotation/Order Form — add price-list `<select>` above items table; on change, `axios.get(route('catalog.price-lists.items', list))` → map products in form rows.

## Architecture
- Migrations: `2026_05_23_900017`, `2026_05_23_900018`.
- Controller: `app/Http/Controllers/Catalog/PriceListController.php`.
- No new service (controller-only — keep simple).

## Related Code Files

**Create:**
- `database/migrations/2026_05_23_900017_create_price_lists_table.php`
- `database/migrations/2026_05_23_900018_create_price_list_items_table.php`
- `app/Models/PriceList.php`
- `app/Models/PriceListItem.php`
- `app/Http/Controllers/Catalog/PriceListController.php`
- `resources/js/Pages/Catalog/PriceLists/Index.vue`
- `resources/js/Pages/Catalog/PriceLists/Form.vue`
- `resources/js/Pages/Catalog/PriceLists/Show.vue`

**Modify:**
- `routes/web.php` — `catalog.` prefix.
- `resources/js/Components/Layout/Sidebar.vue` — under "Danh mục" NavGroup.
- `resources/js/Pages/Sales/Quotations/Form.vue` — add price-list selector + apply logic.
- `resources/js/Pages/Sales/Orders/Form.vue` — same.
- `app/Http/Controllers/Sales/QuotationController.php` — pass `price_lists` prop to `create`/`edit`.
- `app/Http/Controllers/Sales/OrderController.php` — same.
- `database/seeders/RolePermissionSeeder.php`.

## Implementation Steps
1. Migration `price_lists`: `id`, `code` unique, `name`, `valid_from` date nullable, `valid_to` date nullable, `is_default` boolean default false, `notes` text nullable, `created_by` FK users, timestamps. Soft delete: NO.
2. Migration `price_list_items`: `id`, `price_list_id` FK cascadeOnDelete, `product_id` FK restrictOnDelete, `unit_price` decimal(15,2), timestamps. Unique index on (price_list_id, product_id).
3. Model `PriceList`: `$fillable`, casts (`valid_from`, `valid_to` => 'date', `is_default` => 'bool'), `generateCode()` prefix `BG-`. Relations: `items()` HasMany, `creator()`. Boot hook: when saving with `is_default=true`, set others to false.
4. Model `PriceListItem`: `$fillable=['price_list_id','product_id','unit_price']`, casts `unit_price => 'decimal:2'`. Relations: `priceList()`, `product()`.
5. Controller `PriceListController`:
   - `index`: paginate with `withCount('items')`, default-flag visible. `->through(fn)`.
   - `create`: nextCode + empty form.
   - `store`: validate `code`, `name`, dates, `items` array (nullable). Create header + items in transaction.
   - `show`: header + items list with product name.
   - `edit`: same as create with prefill.
   - `update`: validate + sync items (delete existing + recreate, simpler than diff).
   - `destroy`: delete.
   - `items(PriceList $priceList)` GET JSON: `[{product_id, unit_price}]` for client use.
6. Routes:
   ```php
   Route::resource('price-lists', PriceListController::class);
   Route::get('price-lists/{priceList}/items-json', [PriceListController::class,'items'])->name('price-lists.items');
   ```
7. Sidebar inside "Danh mục" NavGroup:
   `<NavItem v-if="can('price-lists.view')" :href="route('catalog.price-lists.index')" icon="tag" sub>Bảng giá</NavItem>`.
8. Seeder: 4 perms; admin (all), sales (view), warehouse (view), director (view).
9. Vue `Index.vue`: table with code, name, valid range, items count, default badge.
10. Vue `Form.vue`: header inputs + repeating item rows (product picker + unit_price); useForm submit.
11. Vue `Show.vue`: header + items table (read-only).
12. **Integration in `Sales/Quotations/Form.vue`** + **`Sales/Orders/Form.vue`**:
    - Add prop `price_lists: Array` (from controller).
    - New ref `selectedPriceListId`.
    - `<select v-model="selectedPriceListId">` above items table with placeholder "Chọn bảng giá (tùy chọn)".
    - On change: `axios.get(route('catalog.price-lists.items', selectedPriceListId))` → returns `{product_id, unit_price}` map. For each row in form items where `row.product_id` present in map, set `row.unit_price = map[row.product_id]`.
    - When new row added with product picked, lookup map (cached client-side) and prefill unit_price if exists.
13. Update `QuotationController::create` + `edit` + `OrderController::create` + `edit` to pass `'price_lists' => PriceList::select('id','code','name')->orderBy('code')->get()`.

## Todo List
- [ ] Migrations (pair)
- [ ] Models with boot default-flag enforcement
- [ ] Controller + 7 routes
- [ ] Vue Index/Form/Show
- [ ] Integrate selector in Quotations/Form.vue
- [ ] Integrate selector in Orders/Form.vue
- [ ] Pass `price_lists` from QuotationController + OrderController
- [ ] Sidebar + Seeder
- [ ] Smoke test: create list → use in quotation → verify unit_price prefilled

## Success Criteria
- Selecting a price list updates unit_price for matching products on quotation/order form.
- Manual edit after selection is preserved (no lock).
- Only one `is_default = true` enforced.
- Cascade delete: removing price list deletes items.

## Risk Assessment
- Decimal precision: ensure casts `decimal:2` to avoid float drift. Order/Quotation item already uses decimal.
- Heavy items in a list could slow JSON endpoint — paginate not needed (one list per request); but cap at ~5000 items in V1.
- Existing Quotation/Order forms may not have product_id on every row (free-text services) — guard `if (row.product_id)`.

## Security Considerations
- `can:price-lists.*` middleware.
- `items()` JSON endpoint requires `auth` + (for safety) `can:price-lists.view`.

## Next Steps
- V2: per-customer assigned price list (default).
- V2: tiered pricing (qty breaks).
