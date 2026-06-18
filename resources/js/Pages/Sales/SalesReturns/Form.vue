<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('sales.sales-returns.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ salesReturn ? 'Sửa phiếu trả hàng bán' : 'Tạo phiếu trả hàng bán' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <!-- Header fields -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã phiếu <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text" readonly
                class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-500" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày trả hàng <span class="text-red-500">*</span></label>
              <input v-model="form.return_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.return_date }" />
              <p v-if="form.errors.return_date" class="mt-1 text-xs text-red-600">{{ form.errors.return_date }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Đơn hàng <span class="text-red-500">*</span></label>
              <SearchableSelect
                v-model="form.order_id"
                :options="orderOptions"
                placeholder="-- Chọn đơn hàng --"
                :disabled="!!salesReturn"
                :has-error="!!form.errors.order_id"
                @change="onOrderChange"
              />
              <p v-if="form.errors.order_id" class="mt-1 text-xs text-red-600">{{ form.errors.order_id }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Kho nhận hàng trả <span class="text-red-500">*</span></label>
              <SearchableSelect
                v-model="form.warehouse_id"
                :options="warehouseOptions"
                placeholder="-- Chọn kho --"
                :has-error="!!form.errors.warehouse_id"
              />
              <p v-if="form.errors.warehouse_id" class="mt-1 text-xs text-red-600">{{ form.errors.warehouse_id }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Lý do trả hàng</label>
              <input v-model="form.reason" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
              <input v-model="form.notes" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
          </div>
        </div>

        <!-- Items -->
        <div v-if="loadingItems" class="bg-white rounded-xl border border-gray-200 p-6 text-center text-gray-400 text-sm">
          Đang tải danh sách hàng hóa...
        </div>

        <div v-else-if="availableItems.length" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-800">Hàng hóa cần trả</h2>
          </div>
          <div class="divide-y divide-gray-100">
            <div v-for="(avail, idx) in availableItems" :key="avail.id" class="p-5 space-y-3">
              <div class="flex items-start gap-4">
                <div class="flex-1">
                  <p class="font-medium text-gray-800">{{ avail.product_name }}
                    <span class="text-xs text-gray-400 font-mono ml-1">{{ avail.product_code }}</span>
                  </p>
                  <p class="text-xs text-gray-500 mt-0.5">
                    Đã giao: {{ avail.delivered_qty }} | Đã trả trước: {{ avail.prior_returned }} |
                    <span class="font-semibold text-orange-600">Tối đa: {{ avail.max_returnable }}</span>
                  </p>
                </div>
                <div class="w-28">
                  <label class="block text-xs font-medium text-gray-600 mb-1">SL trả</label>
                  <input v-model.number="form.items[idx].quantity" type="number" min="0"
                    :max="avail.max_returnable"
                    @change="onQtyChange(idx)"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none"
                    :class="{ 'border-red-500': form.errors[`items.${idx}.quantity`] }" />
                </div>
              </div>

              <!-- Serial picker -->
              <div v-if="avail.has_serial && form.items[idx].quantity > 0 && avail.serials?.length" class="ml-1">
                <p class="text-xs font-medium text-gray-600 mb-2">
                  Chọn serial để trả
                  <span class="text-orange-600">(chọn {{ form.items[idx].quantity }})</span>:
                </p>
                <div class="flex flex-wrap gap-2">
                  <label v-for="s in avail.serials" :key="s.id"
                    class="flex items-center gap-1.5 px-2 py-1 rounded-lg border text-xs font-mono cursor-pointer"
                    :class="isSerialSelected(idx, s.id)
                      ? 'border-primary-500 bg-primary-50 text-primary-700'
                      : 'border-gray-200 bg-white text-gray-600 hover:border-gray-400'">
                    <input type="checkbox" :value="s.id" v-model="form.items[idx].serial_ids" class="sr-only" />
                    {{ s.serial_number }}
                  </label>
                </div>
                <p v-if="serialCountMismatch(idx, avail)" class="mt-1 text-xs text-red-600">
                  Cần chọn đúng {{ form.items[idx].quantity }} serial (đang chọn {{ form.items[idx].serial_ids.length }})
                </p>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="form.order_id && !loadingItems" class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 text-sm text-yellow-800">
          Đơn hàng này không có hàng nào có thể trả.
        </div>

        <p v-if="form.errors.items" class="text-sm text-red-600">{{ form.errors.items }}</p>

        <div class="flex justify-end gap-3">
          <Link :href="salesReturn ? route('sales.sales-returns.show', salesReturn.id) : route('sales.sales-returns.index')"
            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing || !hasItems"
            class="px-5 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-lg disabled:opacity-50">
            {{ salesReturn ? 'Cập nhật' : 'Lưu phiếu trả hàng' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import SearchableSelect from '@/Components/Shared/SearchableSelect.vue';
import axios from 'axios';

const props = defineProps({
  nextCode: String,
  orders: Array,
  warehouses: Array,
  salesReturn: Object,
  preSelectedOrderId: { type: Number, default: null },
});

const orderOptions = computed(() =>
  (props.orders ?? []).map(o => ({
    value: o.id,
    label: `${o.code} — ${o.customer_name}`,
  }))
);
const warehouseOptions = computed(() =>
  (props.warehouses ?? []).map(w => ({ value: w.id, label: w.name }))
);

const availableItems = ref([]);
const loadingItems = ref(false);

const form = useForm({
  code:         props.salesReturn?.code         ?? props.nextCode,
  order_id:     props.salesReturn?.order_id     ?? props.preSelectedOrderId ?? '',
  warehouse_id: props.salesReturn?.warehouse_id ?? '',
  return_date:  props.salesReturn?.return_date  ?? new Date().toISOString().slice(0, 10),
  reason:       props.salesReturn?.reason       ?? '',
  notes:        props.salesReturn?.notes        ?? '',
  items: [],
});

const hasItems = computed(() => form.items.some(i => i.quantity > 0));

async function loadOrderItems(preItems = null) {
  availableItems.value = [];
  form.items = [];
  if (!form.order_id) return;

  loadingItems.value = true;
  try {
    const { data } = await axios.get(route('sales.sales-returns.order-items', form.order_id));
    availableItems.value = data;
    form.items = data.map(item => {
      const existing = preItems?.find(pi => pi.order_item_id === item.id);
      return {
        order_item_id: item.id,
        product_id:    item.product_id,
        quantity:      existing?.quantity   ?? 0,
        unit_price:    existing?.unit_price ?? item.unit_price,
        serial_ids:    existing?.serial_ids ?? [],
      };
    });
  } finally {
    loadingItems.value = false;
  }
}

async function onOrderChange() {
  await loadOrderItems();
}

onMounted(() => {
  if (props.salesReturn) {
    loadOrderItems(props.salesReturn.items);
  } else if (props.preSelectedOrderId) {
    loadOrderItems();
  }
});

function onQtyChange(idx) {
  form.items[idx].serial_ids = [];
}

function isSerialSelected(idx, serialId) {
  return form.items[idx].serial_ids.includes(serialId);
}

function serialCountMismatch(idx, avail) {
  return avail.has_serial
    && form.items[idx].quantity > 0
    && form.items[idx].serial_ids.length !== form.items[idx].quantity;
}

function submit() {
  const activeItems = form.items
    .filter(item => item.quantity > 0)
    .map(item => ({ ...item, quantity: Number(item.quantity) }));

  if (!activeItems.length) {
    alert('Vui lòng nhập số lượng trả cho ít nhất một sản phẩm.');
    return;
  }

  form.transform(data => ({ ...data, items: activeItems }));
  if (props.salesReturn) {
    form.put(route('sales.sales-returns.update', props.salesReturn.id));
  } else {
    form.post(route('sales.sales-returns.store'));
  }
}
</script>
