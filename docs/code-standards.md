# Mini ERP — Code Standards & Codebase Structure

## File Organization

### Directory Structure
```
app/
├── Console/
│   └── Commands/
├── Enums/
│   ├── SerialStatus.php
│   ├── OrderStatus.php
│   ├── LeadStatus.php
│   └── ...
├── Events/
├── Exceptions/
├── Http/
│   ├── Controllers/
│   │   ├── OrderController.php
│   │   ├── LeadController.php
│   │   └── ...
│   └── Requests/
│       ├── StoreOrderRequest.php
│       └── ...
├── Models/
│   ├── Customer.php
│   ├── Order.php
│   ├── Lead.php
│   ├── StockTransfer.php
│   ├── SalesReturn.php
│   ├── PurchaseReturn.php
│   ├── PriceList.php
│   └── ...
├── Notifications/
│   ├── LowStockNotification.php
│   ├── TicketCreatedNotification.php
│   └── InvoiceOverdueNotification.php
└── Services/
    ├── OrderService.php
    ├── LeadService.php
    ├── StockTransferService.php
    ├── SalesReturnService.php
    ├── PurchaseReturnService.php
    └── ...

database/
├── factories/
├── migrations/
│   ├── 2026_05_21_900001_create_users_table.php
│   ├── 2026_05_23_900009_create_leads_table.php
│   └── ...
└── seeders/

resources/
├── js/
│   ├── Pages/
│   │   ├── Orders/
│   │   │   ├── Index.vue
│   │   │   ├── Form.vue
│   │   │   └── Show.vue
│   │   ├── Leads/
│   │   │   ├── Index.vue
│   │   │   ├── Form.vue
│   │   │   └── Show.vue
│   │   └── ...
│   ├── Components/
│   │   ├── StatusBadge.vue
│   │   ├── NotificationDropdown.vue
│   │   └── ...
│   └── Composables/
│       ├── useForm.js
│       ├── useFilters.js
│       └── useNotifications.js

routes/
├── api.php
└── web.php

tests/
├── Feature/
└── Unit/
```

---

## PHP Code Standards

### Model Classes

**Naming:** Singular, PascalCase (e.g., Order, Lead, StockTransfer)

**Structure:**
```php
class Order extends Model
{
    use HasFactory, SoftDeletes, HasActivity;

    // Soft delete for transactional data? NO
    // Only for master data: Product, Customer, Supplier, User, Service
    
    protected $fillable = [
        'customer_id',
        'order_code',
        'status',
        'total_amount',
        'delivery_address',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'total_amount' => 'decimal:2',
    ];

    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stockExits()
    {
        return $this->hasMany(StockExit::class);
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    // Accessors/Mutators (if needed)
    public function getDeliveredQuantityAttribute()
    {
        return $this->items->sum('delivered_quantity');
    }
}
```

### Service Classes

**Naming:** Module + 'Service' (e.g., OrderService, LeadService)

**Pattern:**
```php
class OrderService
{
    public function createOrder(array $data): Order
    {
        DB::beginTransaction();
        try {
            $order = Order::create($data);
            // Generate order code
            $order->update(['order_code' => 'ĐH-' . $order->id]);
            DB::commit();
            return $order;
        } catch (Exception $e) {
            DB::rollBack();
            throw new OrderException($e->getMessage());
        }
    }

    public function confirmOrder(Order $order): void
    {
        if ($order->status !== OrderStatus::NEW) {
            throw new OrderException('Order must be in NEW status');
        }

        DB::beginTransaction();
        try {
            $order->update(['status' => OrderStatus::CONFIRMED]);
            // Auto-update inventory reserved quantities
            $this->reserveInventory($order);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function syncDelivery(Order $order): void
    {
        $totalQty = $order->items->sum('quantity');
        $deliveredQty = $order->items->sum('delivered_quantity');

        if ($deliveredQty >= $totalQty) {
            $order->update(['status' => OrderStatus::DELIVERED]);
        } elseif ($deliveredQty > 0) {
            $order->update(['status' => OrderStatus::PARTIAL_DELIVERED]);
        }
    }

    private function reserveInventory(Order $order): void
    {
        foreach ($order->items as $item) {
            // Implementation
        }
    }
}
```

### Controller Classes

**Naming:** Module + 'Controller' (e.g., OrderController)

**Pattern:**
```php
class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService,
    ) {}

    public function index(Request $request)
    {
        $orders = Order::with(['customer', 'items'])
            ->when($request->status, fn($q) => $q->byStatus($request->status))
            ->when($request->customer_id, fn($q) => $q->where('customer_id', $request->customer_id))
            ->latest()
            ->paginate(15);

        return response()->json(['data' => $orders]);
    }

    public function store(StoreOrderRequest $request)
    {
        $order = $this->orderService->createOrder($request->validated());
        return response()->json(['data' => $order], 201);
    }

    public function show(Order $order)
    {
        return response()->json(['data' => $order->load(['customer', 'items'])]);
    }

    public function update(UpdateOrderRequest $request, Order $order)
    {
        // Only update if status is NEW
        if ($order->status !== OrderStatus::NEW) {
            return response()->json(['message' => 'Cannot edit confirmed order'], 403);
        }

        $order->update($request->validated());
        return response()->json(['data' => $order]);
    }

    public function destroy(Order $order)
    {
        if ($order->status !== OrderStatus::NEW) {
            return response()->json(['message' => 'Cannot delete confirmed order'], 403);
        }

        $order->delete();
        return response()->json(['message' => 'Order deleted']);
    }

    public function confirm(Order $order)
    {
        $this->orderService->confirmOrder($order);
        return response()->json(['data' => $order->refresh()]);
    }
}
```

### Enum Classes

**Naming:** Feature + 'Status' or 'State' (e.g., OrderStatus, LeadStatus)

**Pattern:**
```php
enum OrderStatus: string
{
    case NEW = 'new';
    case CONFIRMED = 'confirmed';
    case PARTIAL_DELIVERED = 'partial_delivered';
    case DELIVERED = 'delivered';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::NEW => 'Mới',
            self::CONFIRMED => 'Xác nhận',
            self::PARTIAL_DELIVERED => 'Giao từng phần',
            self::DELIVERED => 'Đã giao',
            self::COMPLETED => 'Hoàn thành',
            self::CANCELLED => 'Hủy',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::NEW => 'gray',
            self::CONFIRMED => 'blue',
            self::PARTIAL_DELIVERED => 'yellow',
            self::DELIVERED => 'green',
            self::COMPLETED => 'green',
            self::CANCELLED => 'red',
        };
    }
}
```

### Migration Files

**Naming Pattern:** `YYYY_MM_DD_HHMMSS_descriptive_name.php`

**Code Phases:**
- Phase 1–2: 900001–900008
- Phase 3–4: (continuing)
- Phase 5–6: (continuing)
- Phase 7–8: (continuing)
- Phase 9 (Latest): 900009–900021
- Phase 10: 900022+

**Pattern:**
```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->string('order_code')->unique();
            $table->string('status')->default(OrderStatus::NEW->value);
            $table->decimal('total_amount', 15, 2);
            $table->text('delivery_address')->nullable();
            $table->timestamps();
            $table->softDeletes(); // Only for master data
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
```

---

## Vue 3 Code Standards

### Component Naming
- **Files:** PascalCase (OrderIndex.vue, LeadForm.vue)
- **Components:** Match filename

### Index Pages Pattern
```vue
<template>
  <div class="page">
    <PageHeader title="Orders" />
    
    <div class="filters">
      <input v-model="filters.customer_id" placeholder="Customer">
      <select v-model="filters.status">
        <option value="">All Status</option>
        <option value="new">New</option>
      </select>
      <button @click="applyFilters">Filter</button>
      <button @click="resetFilters">Reset</button>
    </div>

    <div class="actions">
      <LinkButton href="/orders/create">Create Order</LinkButton>
      <button @click="downloadTemplate">Download Template</button>
    </div>

    <DataTable :columns="columns" :data="orders" @sort="handleSort">
      <template #status="{ row }">
        <StatusBadge :status="row.status" />
      </template>
      <template #actions="{ row }">
        <LinkButton :href="`/orders/${row.id}`">View</LinkButton>
        <LinkButton :href="`/orders/${row.id}/edit`">Edit</LinkButton>
      </template>
    </DataTable>

    <Pagination :links="orders.links" @change="changePage" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useForm } from '@/Composables/useForm'

const filters = ref({
  customer_id: '',
  status: '',
})

const { data: orders, loading, error } = useForm('/api/orders')

const columns = [
  { key: 'order_code', label: 'Code' },
  { key: 'customer.name', label: 'Customer' },
  { key: 'status', label: 'Status' },
  { key: 'total_amount', label: 'Amount' },
]

const applyFilters = () => {
  // Fetch with filters
}

const resetFilters = () => {
  filters.value = { customer_id: '', status: '' }
}
</script>
```

### Form Pages Pattern
```vue
<template>
  <div class="page">
    <PageHeader :title="isEdit ? 'Edit Order' : 'Create Order'" />
    
    <form @submit.prevent="submit">
      <FormGroup label="Customer" error="form.errors.customer_id">
        <CustomerSelect v-model="form.customer_id" />
      </FormGroup>

      <FormGroup label="Items" error="form.errors.items">
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th>Quantity</th>
              <th>Unit Price</th>
              <th>Total</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, idx) in form.items" :key="idx">
              <td><ProductSelect v-model="item.product_id" /></td>
              <td><input v-model.number="item.quantity" type="number" /></td>
              <td><input v-model.number="item.unit_price" type="number" /></td>
              <td>{{ item.quantity * item.unit_price }}</td>
              <td><button @click="form.items.splice(idx, 1)">Remove</button></td>
            </tr>
          </tbody>
        </table>
        <button @click="form.items.push({})" type="button">Add Item</button>
      </FormGroup>

      <FormGroup label="Delivery Address">
        <textarea v-model="form.delivery_address"></textarea>
      </FormGroup>

      <div class="actions">
        <button type="submit" :disabled="form.processing">Submit</button>
        <LinkButton href="/orders">Cancel</LinkButton>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
  order: Object,
})

const isEdit = !!props.order

const form = useForm({
  customer_id: props.order?.customer_id || '',
  items: props.order?.items || [{}],
  delivery_address: props.order?.delivery_address || '',
})

const submit = () => {
  if (isEdit) {
    form.put(`/api/orders/${props.order.id}`)
  } else {
    form.post('/api/orders')
  }
}
</script>
```

### Composable Pattern
```javascript
// resources/js/Composables/useForm.js
import { ref, computed } from 'vue'

export function useForm(endpoint) {
  const data = ref([])
  const loading = ref(false)
  const error = ref(null)

  const fetch = async (params = {}) => {
    loading.value = true
    try {
      const response = await fetch(endpoint, { params })
      data.value = response.data
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  const save = async (payload) => {
    loading.value = true
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      return response.data
    } catch (e) {
      error.value = e.message
    } finally {
      loading.value = false
    }
  }

  return { data, loading, error, fetch, save }
}
```

---

## Naming Conventions

### Database Tables & Columns
- **Snake_case:** `product_serials`, `stock_transfers`, `purchase_returns`
- **Code Columns:** ALL_CAPS_WITH_PREFIX (ĐH-, TG-, CK-, TH-, THM-, LD-, BG-)
- **Status Columns:** Enum values as lowercase (new, confirmed, draft)
- **Timestamps:** `created_at`, `updated_at`, `deleted_at` (soft delete)

### Model Properties
- **Singular:** Order (not Orders)
- **PascalCase:** Customer, Product, Lead

### API Routes
- **Plural RESTful:** `/api/orders`, `/api/leads`, `/api/price-lists`
- **Hyphenated:** Complex names (stock-transfers, sales-returns)

### Vue Components
- **PascalCase files:** OrderIndex.vue, LeadForm.vue
- **Kebab-case props:** `@on-submit`, `:is-loading`

---

## Key Development Patterns

### Transaction Safety
```php
DB::beginTransaction();
try {
    // Critical operations
    DB::commit();
} catch (Exception $e) {
    DB::rollBack();
    throw $e;
}
```

### Soft Delete Policy
- **Only for master data:** Product, Customer, Supplier, User, Service
- **NOT for transactional:** Order, Lead, StockTransfer, SalesReturn, PurchaseReturn

### Serial Tracking Pattern
```php
// When creating stock movement:
$serial->update([
    'status' => SerialStatus::SOLD,
    'stock_exit_item_id' => $exitItem->id,
]);

// When returning goods:
$serial->update([
    'status' => SerialStatus::IN_STOCK,
    'stock_exit_item_id' => null,
    'sales_return_item_id' => $returnItem->id,
]);
```

### Auto-Code Generation
```php
$order = Order::create($data);
$order->update(['order_code' => 'ĐH-' . $order->id]);

// Result: ĐH-1, ĐH-2, ĐH-123
```

---

## Error Handling Standards

### Custom Exceptions
```php
class OrderException extends Exception {}
class StockTransferException extends Exception {}
class LeadException extends Exception {}
```

### Response Format
```php
// Success
response()->json(['data' => $order, 'message' => 'Order created'], 201)

// Validation Error
response()->json(['message' => 'Validation failed', 'errors' => []], 422)

// Auth/Permission Error
response()->json(['message' => 'Unauthorized'], 403)

// Not Found
response()->json(['message' => 'Order not found'], 404)
```

---

## Testing Standards

### Unit Tests
```php
// tests/Unit/Services/OrderServiceTest.php
it('creates order successfully', function () {
    $order = OrderService::createOrder([
        'customer_id' => 1,
        'total_amount' => 100,
    ]);

    expect($order->order_code)->toMatch('/^ĐH-\d+$/');
});
```

### Feature Tests
```php
// tests/Feature/OrderControllerTest.php
test('can create order', function () {
    $response = $this->post('/api/orders', [
        'customer_id' => 1,
        'items' => [
            ['product_id' => 1, 'quantity' => 2],
        ],
    ]);

    $response->assertStatus(201);
});
```

---

## Performance Standards

### Eager Loading
```php
// Always load relationships
Order::with(['customer', 'items', 'stockExits'])->get()
```

### Indexing
- All foreign keys indexed
- Unique codes indexed
- Status + customer_id composite indexes where applicable

### Query Optimization
- Paginate large result sets (15 items default)
- Use select() to limit columns
- Cache static data (price lists)

---

## Documentation Standards

### Code Comments
- Explain "why", not "what"
- Use for complex business logic only
- Update when code changes

### PHPDoc Comments
```php
/**
 * Create a new order with items and auto-code generation
 *
 * @param array $data Order data (customer_id, total_amount, etc.)
 * @return Order Created order instance
 * @throws OrderException If customer not found or validation fails
 */
public function createOrder(array $data): Order
```

### Vue Documentation
```vue
<!-- Brief component purpose -->
<template>
  <!-- Template code -->
</template>

<!-- Props/Events documentation as JSDoc -->
<script setup>
/**
 * @prop {Order} order - Order object to display
 * @prop {boolean} isEditing - Whether form is in edit mode
 * @emit submit - Emitted when form is submitted
 */
</script>
```

---

## Code Review Checklist

- [ ] Follows naming conventions
- [ ] Uses proper design patterns (Service, Controller, Model)
- [ ] Database changes in migration files
- [ ] Permissions added to seeder
- [ ] Error handling implemented
- [ ] No hardcoded values
- [ ] Tests written (unit + feature)
- [ ] Documentation updated
- [ ] No sensitive data in code
- [ ] Code under 200 lines per file
