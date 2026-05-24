# Phase 07 — Bulk Import Excel (7a) + Audit Log UI (7b)

## Context Links
- `maatwebsite/excel` package (installed) — `php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider"`.
- `spatie/laravel-activitylog` (installed) — `activity_log` table exists (migration 2026_05_21_033715).
- Existing controllers: `Catalog/ProductController.php`, `Crm/CustomerController.php`, `Warehouse/SupplierController.php`.

## Overview
- **Priority:** P2
- **Status:** Completed
- Split into TWO sub-features that can be implemented in parallel:
  - **7a Bulk Import**: Add import-from-Excel to Products, Customers, Suppliers.
  - **7b Audit Log UI**: Admin viewer for `spatie/activitylog` entries.

## Key Insights
- `maatwebsite/excel` import classes use `ToModel`, `WithHeadingRow`, `WithValidation` traits.
- Row-level error reporting: use `SkipsOnError` + `SkipsOnFailure` + collect into session flash; show in modal.
- Template download: build a tiny Excel via `(new SampleExport)->download('template.xlsx')` or use a static `.xlsx` file in `storage/app/templates/`. Decision: dynamic generation (cleaner, validates against current schema).
- Audit log query: `activity_log` already populated by spatie when models use `LogsActivity`. UI only — no model changes (existing log entries assumed). For models not yet logged, leave instrumentation as future work.
- Sidebar Admin section: existing block at bottom of Sidebar.vue uses raw `<NavItem>` without NavGroup; add audit log there.

---

## Part 7a — Bulk Import

### Requirements
- 3 import classes: `ProductImport`, `CustomerImport`, `SupplierImport`.
- Add `import(Request)` + `importTemplate()` to each of: ProductController, CustomerController, SupplierController.
- Existing Index pages get "Import Excel" button + modal with file input + download-template link.
- Row-level errors reported in flash session, shown in modal.

### Architecture
- New folder `app/Imports/` with 3 import classes.
- New folder `app/Exports/` with 3 template export classes (or inline closures).

### Related Code Files

**Create:**
- `app/Imports/ProductImport.php`
- `app/Imports/CustomerImport.php`
- `app/Imports/SupplierImport.php`
- `app/Exports/ProductTemplateExport.php`
- `app/Exports/CustomerTemplateExport.php`
- `app/Exports/SupplierTemplateExport.php`

**Modify:**
- `app/Http/Controllers/Catalog/ProductController.php` — add `import()` + `importTemplate()`.
- `app/Http/Controllers/Crm/CustomerController.php` — same.
- `app/Http/Controllers/Warehouse/SupplierController.php` — same.
- `resources/js/Pages/Catalog/Products/Index.vue` — Import button + modal.
- `resources/js/Pages/Crm/Customers/Index.vue` — Import button + modal.
- `resources/js/Pages/Warehouse/Suppliers/Index.vue` — Import button + modal.
- `routes/web.php` — 6 routes (3 import, 3 template).

### Implementation Steps
1. `ProductImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnError, SkipsOnFailure`:
   - Heading row maps columns: `ma_san_pham`, `ten_san_pham`, `don_vi`, `gia_ban`, `cost_price`, `category_code` (optional, lookup by code).
   - `model(array $row)` returns new Product with mapped fields + auto code if blank via `Product::generateCode()`.
   - `rules()` validates each column.
   - `onError`/`onFailure` collect errors into `public array $errors = []`.
2. `CustomerImport`: same pattern; map `ma_khach_hang`, `ten`, `cong_ty`, `ma_so_thue`, `phone`, `email`, `address`, `lead_status` (map vi label → enum value).
3. `SupplierImport`: map `ma_ncc`, `ten`, `phone`, `email`, `address`, `tax_code`, `bank_*` fields.
4. Template export classes use `FromArray` + `WithHeadings` to emit one example row.
5. Controller pattern (Product example):
   ```php
   public function importTemplate() {
       return Excel::download(new ProductTemplateExport, 'mau-import-san-pham.xlsx');
   }
   public function import(Request $request) {
       $request->validate(['file' => ['required','file','mimes:xlsx,xls,csv']]);
       $import = new ProductImport;
       Excel::import($import, $request->file('file'));
       return back()->with([
           'success' => 'Đã nhập ' . count($import->getImported() ?? []) . ' dòng.',
           'import_errors' => $import->errors,
       ]);
   }
   ```
6. Routes (each module group):
   - `Route::post('products/import', [ProductController::class,'import'])->name('products.import')`
   - `Route::get('products/import-template', [ProductController::class,'importTemplate'])->name('products.import-template')`
   - Repeat for customers + suppliers.
7. Vue Index pages — add:
   - "Import Excel" button next to "Add" button.
   - Modal with: download-template link (`<a :href="route('...import-template')">`) + file input + submit button using `FormData + router.post(route('...import'))`.
   - After submit: show `$page.props.flash.import_errors` if any in error list.

### Todo List (7a)
- [ ] 3 Import classes
- [ ] 3 Template export classes
- [ ] ProductController + 2 methods + routes
- [ ] CustomerController + 2 methods + routes
- [ ] SupplierController + 2 methods + routes
- [ ] Update 3 Vue Index pages
- [ ] Smoke test: download template → fill 3 rows → import → verify

---

## Part 7b — Audit Log UI

### Requirements
- Admin-only page listing `activity_log` entries with filters: causer (user), subject_type, date range.
- Pagination (50/page).
- New permission `admin.activity-logs` granted to admin only.

### Architecture
- Controller: `app/Http/Controllers/Admin/ActivityLogController.php`.
- Vue: `resources/js/Pages/Admin/ActivityLogs/Index.vue`.
- Uses `Spatie\Activitylog\Models\Activity` model.

### Related Code Files

**Create:**
- `app/Http/Controllers/Admin/ActivityLogController.php`
- `resources/js/Pages/Admin/ActivityLogs/Index.vue`

**Modify:**
- `routes/web.php` — under `admin.` prefix.
- `resources/js/Components/Layout/Sidebar.vue` — add `<NavItem>` in admin section.
- `database/seeders/RolePermissionSeeder.php` — add `admin.activity-logs` perm to admin role.

### Implementation Steps
1. Controller `ActivityLogController::index(Request)`:
   - Query `Spatie\Activitylog\Models\Activity::query()->with('causer','subject')`.
   - Filters via `when()`: `causer_id`, `subject_type`, `from`, `to` (created_at between).
   - Paginate 50, `->through(fn)` mapping log fields: `id`, `description`, `event`, `subject_type` (basename), `subject_id`, `causer_name`, `created_at` (formatted), `properties` (cast to array snippet).
   - Inertia render `Admin/ActivityLogs/Index.vue` with `logs` + `users` (for filter dropdown) + `subject_types` (distinct list).
2. Route (inside existing `admin.` group + `role:admin` middleware):
   ```php
   Route::get('activity-logs', [ActivityLogController::class,'index'])->name('activity-logs.index');
   ```
3. Sidebar admin section: `<NavItem v-if="isAdmin" :href="route('admin.activity-logs.index')" icon="clipboard-list">Nhật ký hoạt động</NavItem>`.
4. Vue `Index.vue`: filter form (causer select, subject_type select, from/to date) + table (timestamp, causer, event, subject, description). Click row → expand JSON properties.
5. Seeder: add `admin.activity-logs` perm; assigned to admin role only.

### Todo List (7b)
- [ ] Controller `ActivityLogController`
- [ ] Route + sidebar entry
- [ ] Vue `Admin/ActivityLogs/Index.vue`
- [ ] Seeder perm
- [ ] Smoke test: trigger some CRUD actions → verify entries appear with filters

---

## Combined Success Criteria
- Import: 100% valid file imports all rows; 1 invalid row reports row number + field + reason; valid rows in same file still saved.
- Template download returns Excel file with correct headers + one sample row.
- Audit Log Index renders without N+1 (eager `with('causer')`), filters narrow result set, pagination works.

## Risk Assessment
- Excel import memory: large files (>10k rows) may exceed memory — for V1 cap at 5000 rows per import (validate in controller).
- Lead status / category mapping in Excel — accept both Vi label and enum value (case-insensitive); document in template comments.
- `activity_log` may not capture all CRUD yet — model instrumentation is OUT OF SCOPE here (audit existing entries only). Note in changelog.

## Security Considerations
- 7a routes guarded by existing module `can:` middleware (products.create, customers.create, warehouse.manage).
- 7b guarded by `role:admin` in `admin.` group.
- Uploaded file mimes validated; stored temporarily (auto-discarded by maatwebsite).

## Next Steps
- Instrument remaining models with `LogsActivity` trait to widen audit coverage (separate ticket).
- V2: queued imports for large files using `WithChunkReading` + `ShouldQueue`.
