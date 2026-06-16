# Payroll Module Enhancement Plan
**Date:** 2026-06-16  
**Scope:** Excel export, PDF export, Show.vue column groups, Index.vue filters, fillable fix  
**Risk summary:** Low–Medium. No schema change needed. No existing routes touched (add-only).

---

## 0. Pre-conditions (verify before starting)

- `payroll.view` / `payroll.manage` permissions exist in `RolePermissionSeeder` — **check before running seeder**
- `DejaVu` font or `storage/fonts/` path writable for dompdf

---

## 1. Fix: Payroll::$fillable — missing `total_adjustment`

**File:** `app/Models/Payroll.php`  
**Change:** Append `'total_adjustment'` to `$fillable` array.  
**Migration:** None needed — column already exists.  
**Risk:** Low. Silent mass-assignment bug fix.

---

## 2. Excel Export — PayrollExport class

**File to create:** `app/Exports/PayrollExport.php`  
**Implements:** `WithMultipleSheets`  
**4 inner sheet classes** (can be nested or separate files in same directory):

| Class | Sheet name | Data source |
|---|---|---|
| `PayrollSummarySheet` | Tổng hợp | all `payroll->items` with all column groups A–E |
| `PayrollAttendanceSheet` | Chi tiết công | items: actual/paid/unpaid/overtime days |
| `PayrollJournalSheet` | Bút toán | `payroll->items` each with `salary_journal_entry_id` → load `journalEntry->lines` |
| `PayrollAdjustmentSheet` | Điều chỉnh | items where `adjustment_amount != 0` |

**Formatting per sheet:** `WithHeadings`, `WithStyles`, `WithColumnFormatting`, `ShouldAutoSize`  
- Header row: bold + center + border  
- VND columns: `'#,##0'` format  
- Freeze row 1 (`WithCustomStartCell` + `WithFreezeFirstRow` or manual via `AfterSheet` event)  
- Company header rows above data (via `WithTitle` + `AfterSheet`)

**Risk:** Low–Medium. `journalEntry->lines` eager load needed to avoid N+1.

---

## 3. PDF Export — Blade + dompdf

**File to create:** `resources/views/exports/payroll_pdf.blade.php`  
**Layout:** A4 landscape, inline CSS (dompdf limitation — no external CSS)  
**Sections:**
1. Company header block (name, address, tax code)
2. Title: "BẢNG LƯƠNG THÁNG MM/YYYY"
3. Simplified table — columns: STT | Họ tên | Bộ phận | Lương CB | Phụ cấp | Thưởng | Điều chỉnh | Tổng TN | Khấu trừ | Thực lĩnh
4. Totals row
5. Signature section: Người lập / Kế toán trưởng / Giám đốc

**Font:** Use `DejaVu Sans` (bundled with dompdf) — covers Vietnamese via UTF-8.  
**Risk:** Medium. Vietnamese characters require explicit `font-family: DejaVu Sans` in CSS. Test rendering early.

---

## 4. Controller — add exportExcel + exportPdf methods

**File to modify:** `app/Http/Controllers/HR/PayrollController.php`  
*(Note: scout found it under Accounting namespace — use the actual path)*

**Add 2 methods:**

```
exportExcel(Payroll $payroll): BinaryFileResponse
    authorize('payroll.view')
    eager-load items.employee.department, items.salaryJournalEntry.lines
    return Excel::download(new PayrollExport($payroll), "BL-{$payroll->code}.xlsx")

exportPdf(Payroll $payroll): Response
    authorize('payroll.view')
    eager-load items.employee.department
    $pdf = PDF::loadView('exports.payroll_pdf', compact('payroll'))
    return $pdf->setPaper('A4', 'landscape')->download("BL-{$payroll->code}.pdf")
```

**Risk:** Low. No business logic touched.

---

## 5. Routes — add export routes

**File to modify:** `routes/web.php` (or the relevant route file)  
**Add under payroll group** (require `can:payroll.view` middleware):

```php
GET /payrolls/{payroll}/export-excel   → PayrollController@exportExcel
GET /payrolls/{payroll}/export-pdf     → PayrollController@exportPdf
```

**Risk:** Low.

---

## 6. Show.vue — grouped column headers + export buttons

**File to modify:** `resources/js/Pages/Accounting/Payrolls/Show.vue`

**Changes:**
1. Replace current flat header row with 2-row grouped header (Group A–E as specified).
2. Add attendance columns: `actual_working_days`, `paid_leave_days`, `unpaid_leave_days`, `overtime_days` — these fields already exist in `itemDTO` or the item object; verify `PayrollController::itemDTO()` includes them.
3. Add `department` column — requires `itemDTO()` to include `employee.department.name`; add to eager load in `show()`.
4. Add `contract_type` column — verify `employees` table has this field.
5. Add `bonus` column (already in `$fillable`, check if in `itemDTO`).
6. Add export buttons (top-right of page): "Xuất Excel" → `GET exportExcel route` | "Xuất PDF" → `GET exportPdf route`.

**itemDTO additions needed in controller:**
- `department` → `$item->employee->department->name ?? ''`
- `contract_type` → `$item->employee->contract_type ?? ''`
- `actual_working_days`, `paid_leave_days`, `unpaid_leave_days`, `overtime_days` — confirm already included

**Risk:** Medium. Table is wide; test horizontal scroll on smaller screens. Group header colspan math must be exact.

---

## 7. Index.vue — add period + status filters

**File to modify:** `resources/js/Pages/Accounting/Payrolls/Index.vue`

**Add filter bar** above table:
- Period picker (month/year selector) → `period` query param
- Status dropdown (draft / confirmed / locked) → `status` query param
- "Lọc" button → Inertia `router.get()` with params

**Controller `index()` change:** Add `->when($request->period, fn($q) => $q->where('period', $request->period))` + status filter.

**Risk:** Low.

---

## 8. Permissions

**File to check/modify:** `database/seeders/RolePermissionSeeder.php`

- Verify `payroll.view` and `payroll.manage` exist in the seeder's permission list.
- If missing: add both to the `$permissions` array and to the appropriate roles (accountant, admin → manage; viewer roles → view).
- After seeder update: run `php artisan db:seed --class=RolePermissionSeeder` (confirm with user first — will reset RBAC).

**Route middleware:**
- Export routes → `can:payroll.view`
- `updateAdjustment` already exists → leave as-is (currently no explicit `can:` — add `can:payroll.manage` if missing)

**Risk:** Medium. RBAC seeder resets permissions — coordinate with user.

---

## 9. Tests

**File to create:** `tests/Feature/HR/PayrollExportTest.php`

| # | Test case | Risk |
|---|---|---|
| T1 | GET exportExcel returns 200 + `application/vnd.openxmlformats` content-type | Low |
| T2 | GET exportPdf returns 200 + `application/pdf` content-type | Low |
| T3 | Adjustment +500k → net_salary increases by 500k | Low |
| T4 | Adjustment -200k → net_salary decreases by 200k | Low |
| T5 | User with `payroll.view` can GET exportExcel | Low |
| T6 | User with `payroll.view` cannot PATCH adjustment → 403 | Low |
| T7 | Unauthenticated GET exportExcel → redirect to login (not 403) | Low |

**Setup:** Use existing `PayrollFactory` if available; otherwise create minimal payroll + item in test `setUp()`.

---

## 10. Implementation order (dependencies)

```
Step 1 (fillable fix)          → independent, do first
Step 2 (PayrollExport class)   → independent
Step 3 (PDF blade)             → independent
Step 4 (controller methods)    → after 2 + 3
Step 5 (routes)                → after 4
Step 6 (Show.vue)              → after 5 (needs route names)
Step 7 (Index.vue filters)     → independent
Step 8 (permissions)           → after 4+5; coordinate timing
Step 9 (tests)                 → after 4+5
```

---

## Files summary

| Action | Path |
|---|---|
| Modify | `app/Models/Payroll.php` |
| Create | `app/Exports/PayrollExport.php` |
| Create | `resources/views/exports/payroll_pdf.blade.php` |
| Modify | `app/Http/Controllers/HR/PayrollController.php` (or Accounting namespace — verify) |
| Modify | `routes/web.php` |
| Modify | `resources/js/Pages/Accounting/Payrolls/Show.vue` |
| Modify | `resources/js/Pages/Accounting/Payrolls/Index.vue` |
| Modify | `database/seeders/RolePermissionSeeder.php` |
| Create | `tests/Feature/HR/PayrollExportTest.php` |

**Migration needed:** None. All columns exist. Only `$fillable` fix in model.

---

## Risk register

| Step | Risk | Level | Mitigation |
|---|---|---|---|
| Vietnamese PDF | DejaVu font missing or chars render as boxes | Medium | Test with 1 row before full template |
| Show.vue colspan | Grouped headers span count wrong → misaligned | Medium | Count columns per group carefully |
| N+1 on JournalSheet | Loading JE lines per item in loop | Medium | Eager-load `items.salaryJournalEntry.lines` |
| RBAC seeder | Wipes existing permission assignments | Medium | Confirm with user before seeding |
| itemDTO missing fields | department/contract_type not in current DTO | Low | Inspect `itemDTO()` and add fields |
