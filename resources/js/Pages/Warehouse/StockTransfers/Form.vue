<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('warehouse.stock-transfers.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ isEdit ? 'Chỉnh sửa phiếu chuyển kho' : 'Tạo phiếu chuyển kho' }}
        </h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <!-- Basic info -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div v-if="!isEdit">
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã phiếu <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.code }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày chuyển <span class="text-red-500">*</span></label>
              <input v-model="form.transfer_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.transfer_date }" />
              <p v-if="form.errors.transfer_date" class="mt-1 text-xs text-red-600">{{ form.errors.transfer_date }}</p>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Kho nguồn <span class="text-red-500">*</span></label>
              <select v-model="form.from_warehouse_id" @change="onFromWarehouseChange"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.from_warehouse_id }">
                <option value="">-- Chọn kho nguồn --</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
              </select>
              <p v-if="form.errors.from_warehouse_id" class="mt-1 text-xs text-red-600">{{ form.errors.from_warehouse_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Kho đích <span class="text-red-500">*</span></label>
              <select v-model="form.to_warehouse_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.to_warehouse_id }">
                <option value="">-- Chọn kho đích --</option>
                <option v-for="w in toWarehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
              </select>
              <p v-if="form.errors.to_warehouse_id" class="mt-1 text-xs text-red-600">{{ form.errors.to_warehouse_id }}</p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>
        </div>

        <!-- Items -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
              <h2 class="text-base font-semibold text-gray-800">Hàng hóa chuyển kho</h2>
              <p v-if="!form.from_warehouse_id" class="text-xs text-amber-600 mt-0.5">Chọn kho nguồn để xem tồn kho và serial</p>
            </div>
            <button type="button" @click="addRow"
              class="flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium text-gray-700">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Thêm dòng
            </button>
          </div>

          <div class="divide-y divide-gray-100">
            <div v-for="(item, index) in form.items" :key="index" class="p-5 space-y-3">
              <div class="flex items-start gap-3">
                <div class="flex-1">
                  <label class="block text-xs font-medium text-gray-600 mb-1">Sản phẩm</label>
                  <select v-model="item.product_id" @change="onProductChange(index)"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 outline-none"
                    :class="{ 'border-red-500': form.errors[`items.${index}.product_id`] }">
                    <option value="">-- Chọn sản phẩm --</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">
                      [{{ p.code }}] {{ p.name }}
                    </option>
                  </select>
                  <p v-if="form.errors[`items.${index}.product_id`]" class="mt-1 text-xs text-red-600">
                    {{ form.errors[`items.${index}.product_id`] }}
                  </p>
                </div>

                <div class="w-32">
                  <label class="block text-xs font-medium text-gray-600 mb-1">Số lượng</label>
                  <input v-model.number="item.quantity" type="number" min="1"
                    @change="onQuantityChange(index)"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm text-right focus:ring-2 focus:ring-primary-500 outline-none"
                    :class="{ 'border-red-500': form.errors[`items.${index}.quantity`] }" />
                  <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-xs text-red-600">
                    {{ form.errors[`items.${index}.quantity`] }}
                  </p>
                </div>

                <div class="pt-6">
                  <button type="button" @click="removeRow(index)"
                    class="p-2 text-gray-400 hover:text-red-500 transition-colors rounded">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Stock hint -->
              <div v-if="item.product_id && form.from_warehouse_id" class="text-xs text-gray-500">
                <span v-if="getProduct(item.product_id)?.has_serial">
                  Serial trong kho: <strong>{{ getAvailableSerials(item.product_id).length }}</strong>
                </span>
              </div>

              <!-- Serial selection for serial-tracked products -->
              <div v-if="item.product_id && getProduct(item.product_id)?.has_serial && form.from_warehouse_id"
                class="bg-blue-50 rounded-lg p-4 space-y-3">
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 3.5V16M4 16v.5M4 9v.5M4 6h.01" />
                    </svg>
                    <span class="text-sm font-semibold text-blue-800">Chọn Serial</span>
                    <span class="px-2 py-0.5 rounded-full text-xs font-bold"
                      :class="item.serial_ids.length === item.quantity ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
                      {{ item.serial_ids.length }}/{{ item.quantity }}
                    </span>
                  </div>
                  <button v-if="item.serial_ids.length > 0" type="button" @click="item.serial_ids = []"
                    class="text-xs text-gray-400 hover:text-red-500 transition-colors">Bỏ chọn tất cả</button>
                </div>

                <div v-if="getAvailableSerials(item.product_id).length === 0"
                  class="text-xs text-amber-700 bg-amber-50 rounded px-3 py-2">
                  Không có serial nào trong kho nguồn cho sản phẩm này.
                </div>

                <div v-else class="flex flex-wrap gap-1.5 max-h-40 overflow-y-auto">
                  <button
                    v-for="s in getAvailableSerials(item.product_id)"
                    :key="s.id"
                    type="button"
                    @click="toggleSerial(index, s.id)"
                    :disabled="!item.serial_ids.includes(s.id) && item.serial_ids.length >= item.quantity"
                    class="px-2.5 py-1 rounded-lg font-mono text-xs border transition-colors"
                    :class="item.serial_ids.includes(s.id)
                      ? 'bg-blue-600 text-white border-blue-600'
                      : item.serial_ids.length >= item.quantity
                        ? 'bg-gray-50 text-gray-400 border-gray-200 cursor-not-allowed'
                        : 'bg-white text-gray-700 border-blue-200 hover:border-blue-500'">
                    {{ s.serial_number }}
                  </button>
                </div>

                <p v-if="form.errors[`items.${index}.serial_ids`]" class="text-xs text-red-600">
                  {{ form.errors[`items.${index}.serial_ids`] }}
                </p>
              </div>
            </div>

            <div v-if="!form.items.length" class="px-5 py-8 text-center text-gray-400 text-sm">
              Chưa có hàng hóa. Nhấn "Thêm dòng" để bắt đầu.
            </div>
          </div>

          <p v-if="form.errors.items" class="px-5 py-2 text-xs text-red-600 border-t border-gray-100">
            {{ form.errors.items }}
          </p>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (isEdit ? 'Cập nhật' : 'Tạo phiếu') }}
          </button>
          <Link :href="route('warehouse.stock-transfers.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  nextCode: String,
  transfer: Object,
  warehouses: Array,
  products: Array,
});

const isEdit = computed(() => !!props.transfer?.id);
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  code: props.nextCode ?? '',
  from_warehouse_id: props.transfer?.from_warehouse_id ?? '',
  to_warehouse_id: props.transfer?.to_warehouse_id ?? '',
  transfer_date: props.transfer?.transfer_date ?? today,
  notes: props.transfer?.notes ?? '',
  items: props.transfer?.items?.map(i => ({
    product_id: i.product_id,
    quantity: i.quantity,
    serial_ids: i.serial_ids ?? [],
  })) ?? [],
});

const toWarehouses = computed(() =>
  props.warehouses.filter(w => w.id !== form.from_warehouse_id)
);

const getProduct = (productId) => props.products.find(p => p.id === productId);

const getAvailableSerials = (productId) => {
  const product = getProduct(productId);
  return product?.serials ?? [];
};

const onFromWarehouseChange = () => {
  // Reset to_warehouse if same, reload serials via Inertia visit
  if (form.to_warehouse_id === form.from_warehouse_id) {
    form.to_warehouse_id = '';
  }
  // Reload page with new warehouse to get fresh serial data
  if (form.from_warehouse_id) {
    router.visit(
      isEdit.value
        ? route('warehouse.stock-transfers.edit', props.transfer.id)
        : route('warehouse.stock-transfers.create'),
      {
        method: 'get',
        data: { from_warehouse_id: form.from_warehouse_id },
        preserveState: true,
        preserveScroll: true,
        only: ['products'],
      }
    );
  }
  // Clear serial selections on warehouse change
  form.items.forEach(item => { item.serial_ids = []; });
};

const onProductChange = (index) => {
  form.items[index].serial_ids = [];
};

const onQuantityChange = (index) => {
  const item = form.items[index];
  if (item.quantity < 1) item.quantity = 1;
  // Trim serial selection if more than quantity
  if (item.serial_ids.length > item.quantity) {
    item.serial_ids = item.serial_ids.slice(0, item.quantity);
  }
};

const toggleSerial = (index, serialId) => {
  const item = form.items[index];
  const pos = item.serial_ids.indexOf(serialId);
  if (pos >= 0) {
    item.serial_ids.splice(pos, 1);
  } else if (item.serial_ids.length < item.quantity) {
    item.serial_ids.push(serialId);
  }
};

const addRow = () => {
  form.items.push({ product_id: '', quantity: 1, serial_ids: [] });
};

const removeRow = (index) => {
  form.items.splice(index, 1);
};

const submit = () => {
  if (isEdit.value) {
    form.put(route('warehouse.stock-transfers.update', props.transfer.id));
  } else {
    form.post(route('warehouse.stock-transfers.store'));
  }
};
</script>
