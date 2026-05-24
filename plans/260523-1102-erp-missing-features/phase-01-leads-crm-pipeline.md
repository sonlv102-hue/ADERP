# Phase 01 — Leads / CRM Pipeline

## Context Links
- Enum: `app/Enums/LeadStatus.php` (already exists, reuse)
- Pattern reference: `app/Http/Controllers/Crm/CustomerController.php`, `app/Models/Customer.php`
- Vue reference: `resources/js/Pages/Crm/Customers/Index.vue`

## Overview
- **Priority:** P2
- **Status:** Completed
- New `leads` table + Lead model. Separate from customers. Convert-to-customer action.

## Key Insights
- `LeadStatus` enum already exists — do NOT create. Use `casts(): ['status' => LeadStatus::class]`.
- `leads.code` uses `KH-` prefix but its OWN sequence (not the customers one). Different table.
- Soft delete: NO (transactional list, but treat like master since records can be archived). Decision: NO SoftDeletes — keep simple.
- Convert action: copy fields into `customers` table, mark lead status = Won, link `customer_id` on lead.

## Requirements
- CRUD with pagination (20/page), filter by status + assigned_to + source.
- Convert lead → Customer button (visible only when status = Won or Negotiation).
- Permissions: `leads.view`, `leads.create`, `leads.edit`, `leads.delete`, `leads.convert`.
- Sidebar item under existing CRM NavGroup.

## Architecture
- Controller: `app/Http/Controllers/Crm/LeadController.php` injects `LeadService` via constructor.
- Service: `app/Services/LeadService.php` — `convertToCustomer(Lead $lead): Customer`.
- Model `Lead`: `belongsTo` assignedUser (User), `belongsTo` customer (nullable, set after convert).
- Vue pages under `resources/js/Pages/Crm/Leads/`.

## Related Code Files

**Create:**
- `database/migrations/2026_05_23_900009_create_leads_table.php`
- `app/Models/Lead.php`
- `app/Http/Controllers/Crm/LeadController.php`
- `app/Services/LeadService.php`
- `resources/js/Pages/Crm/Leads/Index.vue`
- `resources/js/Pages/Crm/Leads/Form.vue`
- `resources/js/Pages/Crm/Leads/Show.vue`

**Modify:**
- `routes/web.php` — add resource + `convert` POST under `crm.` prefix.
- `resources/js/Components/Layout/Sidebar.vue` — add `<NavItem>` under CRM NavGroup.
- `database/seeders/RolePermissionSeeder.php` — add 5 permissions; assign to `admin`, `sales`, `director`.

## Implementation Steps
1. Migration `2026_05_23_900009_create_leads_table.php`:
   - `id`, `code` unique, `customer_name`, `phone`, `email` nullable, `source` nullable, `assigned_to` FK users nullOnDelete, `status` default 'new', `next_follow_up` date nullable, `expected_value` decimal(15,2) default 0, `notes` text nullable, `customer_id` FK customers nullOnDelete (set after convert), `created_by` FK users, timestamps.
2. Model `Lead`:
   - `$fillable` exact list, `casts(): ['status' => LeadStatus::class, 'next_follow_up' => 'date', 'expected_value' => 'decimal:2']`.
   - `generateCode()`: prefix `KH-` + str_pad 4 zeros (uses `Lead::orderByDesc('id')->value('code')`). Note: separate sequence from customers. *(Optional: use prefix `LD-` if conflict naming with customers is concerning; keep `KH-` per spec.)*
   - Relations: `assignedUser()` BelongsTo User, `customer()` BelongsTo Customer, `creator()` BelongsTo User.
3. Service `LeadService`:
   - `convertToCustomer(Lead $lead): Customer` — DB::transaction: create Customer (code via `Customer::generateCode()`), update `$lead->customer_id` + `$lead->status = LeadStatus::Won`. Throw RuntimeException if already converted.
4. Controller `LeadController` (under 200 lines):
   - Constructor inject `LeadService`.
   - `index` Inertia render with `->through(fn)` mapping like CustomerController. Filter `status`, `assigned_to`, `source` via `when()`.
   - `create`, `store`, `show`, `edit`, `update`, `destroy` mirror CustomerController.
   - `convert(Lead $lead)` POST → calls service, redirect to customers.show.
5. Vue `Index.vue`: table with code, customer_name, phone, status (StatusBadge), assigned_user, next_follow_up, expected_value, actions (Show/Edit/Delete/Convert when not yet converted).
6. Vue `Form.vue`: `useForm`, all fields incl. select for status, assigned_to, source. Submit via `form.post(route('crm.leads.store'))` for create or `form.put` for update.
7. Vue `Show.vue`: read-only display + Convert button `v-if="lead.status === 'won' || lead.status === 'negotiation'" && can('leads.convert')`.
8. Routes (in `crm.` prefix group, `can:leads.view` middleware):
   ```php
   Route::resource('leads', LeadController::class);
   Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
   ```
9. Sidebar — add inside existing CRM NavGroup:
   ```vue
   <NavItem v-if="can('leads.view')" :href="route('crm.leads.index')" icon="user-plus" sub>Khách hàng tiềm năng</NavItem>
   ```
10. RolePermissionSeeder: add `leads.view`, `leads.create`, `leads.edit`, `leads.delete`, `leads.convert`. Assign to `admin` (all), `sales` (all 5), `director` (view).
11. Run `php artisan migrate` + `db:seed --class=RolePermissionSeeder` to verify.

## Todo List
- [ ] Migration `2026_05_23_900009_create_leads_table.php`
- [ ] Model `app/Models/Lead.php`
- [ ] Service `app/Services/LeadService.php`
- [ ] Controller `app/Http/Controllers/Crm/LeadController.php`
- [ ] Vue `Index.vue`
- [ ] Vue `Form.vue`
- [ ] Vue `Show.vue`
- [ ] Routes
- [ ] Sidebar entry
- [ ] Seed permissions
- [ ] Manual smoke test: create → edit → convert → verify customer appears

## Success Criteria
- Migrate runs clean. Index lists leads with StatusBadge color matching LeadStatus.
- Convert produces a Customer row with same name/phone/email; lead.customer_id set; lead.status = Won.
- `can('leads.view')` guard hides nav for users without permission.
- No file > 200 lines.

## Risk Assessment
- Duplicated `KH-` prefix between `customers` and `leads` is confusing but acceptable (different tables). If user objects, switch lead prefix to `LD-` in `generateCode()` only.
- Convert idempotency: throw if `$lead->customer_id !== null`.

## Security Considerations
- `can:leads.*` middleware on resource group.
- `assigned_to` validated `exists:users,id`.

## Next Steps
- After Phase 1 ships, consider lead activity timeline (out of scope here).
