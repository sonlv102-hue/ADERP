# Mini ERP — Quick Recipes

Common task formulas and patterns for rapid development.

## Creating a New Module (Checklist)
1. **Migration** → `2026_05_21_{phase}{seq}` in `database/migrations/`
2. **Model** → `app/Models/{ModuleName}.php` (add `casts()`, `generateCode()`)
3. **Enum** (if stateful) → `app/Enums/{ModuleName}Status.php` with `label()`, `color()`
4. **Service** (if complex) → `app/Services/{ModuleName}Service.php` with FSM logic
5. **Controller** → `app/Http/Controllers/{Module}/{ModuleName}Controller.php`
6. **Routes** → Add to `routes/web.php` with `can:permission` middleware
7. **Vue Pages** → `resources/js/Pages/{Module}/{ModuleName}/Index.vue`, `Form.vue`, `Show.vue`
8. **Seeder** → Add to `database/seeders/` and call from `DatabaseSeeder`
9. **Permission** → Add to `RolePermissionSeeder.php` and run seeder
10. **Tests** → `tests/Feature/{Module}` or `tests/Unit`

## generateCode() Template
```php
public static function generateCode(): string {
    $last = static::withTrashed()->max('id') ?? 0;
    return 'PFX-' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
}
```
Add to Model constructor: `$this->code = static::generateCode();`

## FSM Service Template
```php
class MyService {
    public function confirm(MyModel $record): void {
        if ($record->status !== MyStatus::Draft)
            throw new \RuntimeException('Invalid state.');
        DB::transaction(function () use ($record) {
            // Create related records
            $record->update(['status' => MyStatus::Confirmed]);
            $record->logs()->create(['action' => 'confirmed']);
        });
    }
}
```

## PDF Generation
In Controller:
```php
$pdf = Pdf::loadView('pdf.invoice', ['invoice' => $invoice]);
return $pdf->download('invoice-' . $invoice->code . '.pdf');
```

Blade template at `resources/views/pdf/invoice.blade.php`:
```blade
<h1>{{ $invoice->code }}</h1>
<p>{{ formatVnd($invoice->total) }}</p>
```

## Adding a Permission
1. Edit `database/seeders/RolePermissionSeeder.php`:
   - Add `'module.action'` to `$permissions` array
   - Add role→permission assignment in `$roles` array
2. Run: `php artisan db:seed --class=RolePermissionSeeder`
3. Add route: `->middleware('can:module.action')`
4. Add Vue guard: `v-if="can('module.action')"`

## Filter + Paginate Pattern
```php
$query = MyModel::with('relation')->orderByDesc('id');

if ($request->search) {
    $query->where('name', 'like', '%' . $request->search . '%');
}

if ($request->status) {
    $query->where('status', $request->status);
}

$items = $query->paginate(20)->through(fn($m) => [
    'id' => $m->id,
    'code' => $m->code,
    'status_label' => $m->status->label(),
    'status_color' => $m->status->color(),
]);
```

## Tab Bar: Adding New URL
In `resources/js/composables/useTabs.js`, update `URL_TITLES` map:
```js
const URL_TITLES = {
  '/crm/customers': 'Customers',
  '/module/items/123': 'Item #123',
  // Add new routes here
};
```

## Serial Tracking in Entry/Exit
In `StockEntryService::confirm()`:
```php
foreach ($entry->items as $item) {
    for ($i = 0; $i < $item->quantity; $i++) {
        ProductSerial::create([
            'product_id' => $item->product_id,
            'serial_number' => generate_serial(),
            'warehouse_id' => $entry->warehouse_id,
            'status' => SerialStatus::InStock,
        ]);
    }
    $item->product->recordMovement(
        $entry->warehouse_id, 
        $item->quantity, 
        'in', 
        'stock_entry', 
        $entry->id
    );
}
```

## Snapshot Product Name in Order
Always snapshot at creation — never reference product.name directly in views:
```php
'product_id' => $product->id,
'name' => $product->name,  // Snapshot at creation
'quantity' => $request->quantity,
```

## Calculate Amount Due (Invoice Pattern)
```php
$invoice->amount_due = $invoice->total - $invoice->payments->sum('amount');
```

Or computed property:
```php
public function amountDue(): float {
    return $this->total - $this->payments->sum('amount');
}
```

Always include in Inertia DTO:
```php
return ['invoice' => [..., 'amount_due' => $invoice->amountDue()]];
```
