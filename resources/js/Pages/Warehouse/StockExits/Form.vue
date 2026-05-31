<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('warehouse.stock-exits.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ exit ? 'Sửa phiếu xuất kho' : 'Tạo phiếu xuất kho' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã phiếu <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text" :readonly="!!exit"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.code, 'bg-gray-50 cursor-not-allowed': exit }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày xuất <span class="text-red-500">*</span></label>
              <input v-model="form.exit_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.exit_date }" />
              <p v-if="form.errors.exit_date" class="mt-1 text-xs text-red-600">{{ form.errors.exit_date }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Kho <span class="text-red-500">*</span></label>
              <select v-model="form.warehouse_id" @change="onWarehouseChange"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.warehouse_id }">
                <option value="">-- Chọn kho --</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
              </select>
              <p v-if="form.errors.warehouse_id" class="mt-1 text-xs text-red-600">{{ form.errors.warehouse_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Khách hàng</label>
              <select v-model="form.customer_id" @change="onCustomerChange"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.customer_id }">
                <option :value="null">-- Không có --</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
              </select>
              <p v-if="form.errors.customer_id" class="mt-1 text-xs text-red-600">{{ form.errors.customer_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Đơn hàng liên kết</label>
              <select v-model="form.order_id" @change="onOrderChange"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.order_id }">
                <option :value="null">-- Không liên kết --</option>
                <option v-for="o in customerOrders" :key="o.id" :value="o.id">
                  {{ o.code }} ({{ o.status_label }})
                </option>
              </select>
              <p v-if="form.errors.order_id" class="mt-1 text-xs text-red-600">{{ form.errors.order_id }}</p>
              <div v-if="selectedOrderItems.length" class="mt-2 p-2 bg-blue-50 rounded-lg text-xs text-blue-800 space-y-1">
                <p class="font-semibold">Số lượng còn cần giao:</p>
                <div v-for="i in selectedOrderItems" :key="i.product_id" class="flex justify-between">
                  <span>{{ i.product_name }}</span>
                  <span :class="i.remaining <= 0 ? 'text-green-600 font-semibold' : 'font-semibold'">
                    {{ i.remaining <= 0 ? 'Đã giao đủ' : `còn ${i.remaining}` }}
                  </span>
                </div>
              </div>
            </div>

            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Lý do xuất</label>
              <input v-model="form.reason" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.reason }" />
              <p v-if="form.errors.reason" class="mt-1 text-xs text-red-600">{{ form.errors.reason }}</p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.notes }" />
            <p v-if="form.errors.notes" class="mt-1 text-xs text-red-600">{{ form.errors.notes }}</p>
          </div>
        </div>

        <div v-if="form.order_id && !hasOrderContract"
          class="flex items-start gap-3 bg-amber-50 border border-amber-300 rounded-xl px-5 py-4">
          <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
          </svg>
          <div class="flex-1">
            <p class="text-sm font-semibold text-amber-800">Đơn hàng chưa có hợp đồng bán</p>
            <p class="text-xs text-amber-700 mt-0.5">
              Đề nghị tạo hợp đồng bán trước khi xuất kho để đảm bảo đầy đủ chứng từ pháp lý.
              <Link :href="route('sales.contracts.create')" class="underline font-medium ml-1">Tạo hợp đồng →</Link>
            </p>
          </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-800">Chi tiết hàng hóa</h2>
            <button type="button" @click="addRow"
              class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Thêm dòng
            </button>
          </div>
          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Sản phẩm</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn vị</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">SL</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn giá</th>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Thành tiền</th>
                <th class="px-5 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <template v-for="(item, index) in form.items" :key="index">
                <tr>
                  <td class="px-5 py-3">
                    <select v-model="item.product_id" @change="onProductChange(index)"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                      :class="{ 'border-red-500': form.errors[`items.${index}.product_id`] }">
                      <option value="">-- Chọn sản phẩm --</option>
                      <option v-for="p in products" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option>
                    </select>
                  </td>
                  <td class="px-5 py-3">
                    <input :value="itemUnit(item.product_id)" type="text" readonly
                      class="w-24 px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 outline-none" />
                  </td>
                  <td class="px-5 py-3">
                    <input v-model.number="item.quantity" type="number" min="1" @change="onQuantityChange(index)"
                      class="w-24 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                      :class="{ 'border-red-500': form.errors[`items.${index}.quantity`] }" />
                  </td>
                  <td class="px-5 py-3">
                    <input v-model.number="item.unit_price" type="number" min="0"
                      class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                      :class="{ 'border-red-500': form.errors[`items.${index}.unit_price`] }" />
                  </td>
                  <td class="px-5 py-3 text-gray-700 font-medium">
                    {{ formatVnd(item.quantity * item.unit_price) }}
                  </td>
                  <td class="px-5 py-3 text-right">
                    <button type="button" @click="removeRow(index)"
                      class="text-red-500 hover:text-red-700">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </td>
                </tr>

                <!-- Serial picker row — shown for all products when warehouse is selected -->
                <tr v-if="item.product_id && form.warehouse_id" class="bg-blue-50">
                  <td colspan="6" class="px-5 py-3">
                    <div class="flex items-start gap-3">
                      <span class="text-xs font-medium text-blue-700 mt-1 shrink-0">
                        Serial
                        <span class="text-gray-500">
                          ({{ item.serial_ids.length }}/{{ item.quantity }})
                        </span>:
                      </span>
                      <div v-if="availableSerials(index).length === 0" class="text-xs text-orange-600 italic">
                        Không có serial nào trong kho cho sản phẩm này
                      </div>
                      <div v-else class="flex flex-wrap gap-2">
                        <label
                          v-for="s in availableSerials(index)"
                          :key="s.id"
                          class="flex items-center gap-1.5 px-2 py-1 rounded border text-xs cursor-pointer select-none"
                          :class="item.serial_ids.includes(s.id)
                            ? 'bg-blue-600 border-blue-600 text-white'
                            : 'bg-white border-gray-300 text-gray-700 hover:border-blue-400'">
                          <input
                            type="checkbox"
                            :value="s.id"
                            :checked="item.serial_ids.includes(s.id)"
                            @change="toggleSerial(index, s.id)"
                            class="hidden" />
                          {{ s.serial_number }}
                        </label>
                      </div>
                    </div>
                    <p v-if="form.errors[`items.${index}.serial_ids`]" class="mt-1 text-xs text-red-600">
                      {{ form.errors[`items.${index}.serial_ids`] }}
                    </p>
                  </td>
                </tr>
              </template>
              <tr v-if="!form.items.length">
                <td colspan="6" class="px-5 py-8 text-center text-gray-400">Chưa có hàng hóa nào. Nhấn "Thêm dòng" để bắt đầu.</td>
              </tr>
            </tbody>
            <tfoot v-if="form.items.length" class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td colspan="4" class="px-5 py-3 text-right font-semibold text-gray-700">Tổng cộng:</td>
                <td class="px-5 py-3 font-bold text-gray-900">{{ formatVnd(grandTotal) }}</td>
                <td />
              </tr>
            </tfoot>
          </table>
          <p v-if="form.errors.items" class="px-5 py-2 text-xs text-red-600">{{ form.errors.items }}</p>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : exit ? 'Cập nhật phiếu xuất' : 'Tạo phiếu xuất kho' }}
          </button>
          <Link :href="route('warehouse.stock-exits.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  nextCode: String,
  warehouses: Array,
  customers: Array,
  products: Array,
  serials: Array,
  orders: { type: Array, default: () => [] },
  exit: { type: Object, default: null },
});

const { formatVnd } = useCurrency();

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  code:         props.exit?.code         ?? props.nextCode ?? '',
  exit_date:    props.exit?.exit_date    ?? today,
  warehouse_id: props.exit?.warehouse_id ?? '',
  customer_id:  props.exit?.customer_id  ?? null,
  order_id:     props.exit?.order_id     ?? null,
  reason:       props.exit?.reason       ?? '',
  notes:        props.exit?.notes        ?? '',
  items: props.exit?.items?.map(item => ({
    product_id: item.product_id,
    quantity:   item.quantity,
    unit_price: item.unit_price,
    serial_ids: item.serial_ids ?? [],
  })) ?? [],
});

const customerOrders = computed(() =>
  form.customer_id
    ? props.orders.filter(o => o.customer_id === form.customer_id)
    : props.orders
);

const selectedOrder = computed(() =>
  form.order_id ? props.orders.find(o => o.id === form.order_id) : null
);

const selectedOrderItems = computed(() => selectedOrder.value?.items ?? []);

const hasOrderContract = computed(() =>
  !form.order_id || (selectedOrder.value?.has_contract ?? true)
);

const onCustomerChange = () => {
  if (form.order_id) {
    const valid = customerOrders.value.some(o => o.id === form.order_id);
    if (!valid) { form.order_id = null; form.items = []; }
  }
};

const onOrderChange = () => {
  if (!form.order_id) return;
  const order = props.orders.find(o => o.id === form.order_id);
  if (!order) return;
  // Auto-fill customer từ đơn hàng
  form.customer_id = order.customer_id;
  // Auto-fill items còn cần giao (remaining > 0), lấy giá từ đơn hàng
  const filled = order.items
    .filter(i => i.remaining > 0)
    .map(i => ({ product_id: i.product_id, quantity: i.remaining, unit_price: i.unit_price, serial_ids: [] }));
  if (filled.length) form.items = filled;
};

const addRow = () => {
  form.items.push({ product_id: '', quantity: 1, unit_price: 0, serial_ids: [] });
};

const removeRow = (index) => {
  form.items.splice(index, 1);
};

const onWarehouseChange = () => {
  form.items.forEach(item => { item.serial_ids = []; });
};

const onProductChange = (index) => {
  const productId = form.items[index].product_id;
  // Ưu tiên giá từ đơn hàng liên kết (đã tính chiết khấu theo báo giá)
  const order = form.order_id ? props.orders.find(o => o.id === form.order_id) : null;
  const orderItem = order?.items?.find(i => i.product_id === productId);
  if (orderItem) {
    form.items[index].unit_price = orderItem.unit_price;
  } else {
    const product = props.products.find(p => p.id === productId);
    form.items[index].unit_price = Number(product?.sell_price ?? 0);
  }
  form.items[index].serial_ids = [];
};

const onQuantityChange = (index) => {
  const item = form.items[index];
  if (item.serial_ids.length > item.quantity) {
    item.serial_ids.splice(item.quantity);
  }
};

const availableSerials = (index) => {
  const item = form.items[index];
  if (!item.product_id || !form.warehouse_id) return [];
  const usedElsewhere = new Set(
    form.items.filter((_, i) => i !== index).flatMap(r => r.serial_ids)
  );
  return (props.serials ?? []).filter(
    s => s.product_id === item.product_id &&
         s.warehouse_id === form.warehouse_id &&
         !usedElsewhere.has(s.id)
  );
};

const toggleSerial = (index, serialId) => {
  const item = form.items[index];
  const pos = item.serial_ids.indexOf(serialId);
  if (pos >= 0) {
    item.serial_ids.splice(pos, 1);
  } else {
    item.serial_ids.push(serialId);
  }
};

const itemUnit = (productId) => props.products.find(p => p.id === productId)?.unit ?? '';

const grandTotal = computed(() =>
  form.items.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0)
);

const submit = () => {
  if (props.exit) {
    form.put(route('warehouse.stock-exits.update', props.exit.id));
  } else {
    form.post(route('warehouse.stock-exits.store'));
  }
};
</script>
