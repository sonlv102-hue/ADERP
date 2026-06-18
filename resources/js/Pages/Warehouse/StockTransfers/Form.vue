<template>
  <AppLayout>
    <div class="max-w-5xl space-y-5">

      <!-- Page header -->
      <div class="flex items-center gap-3">
        <Link
          :href="route('warehouse.stock-transfers.index')"
          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <div>
          <p class="mb-0.5 text-xs font-medium text-gray-400">Kho / Chuyển kho</p>
          <h1 class="text-xl font-bold text-gray-900">
            {{ isEdit ? 'Sửa phiếu chuyển kho' : 'Tạo phiếu chuyển kho' }}
          </h1>
        </div>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- ─── Section 1: Thông tin phiếu ─── -->
        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
          <div class="flex items-center gap-2.5 border-b border-gray-100 bg-gray-50/60 px-6 py-4">
            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100">
              <svg class="h-3.5 w-3.5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
              </svg>
            </div>
            <h2 class="text-sm font-semibold text-gray-800">Thông tin phiếu</h2>
          </div>

          <div class="p-6 space-y-5">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">

              <!-- Mã phiếu -->
              <FormField v-if="!isEdit" label="Mã phiếu" required :error="form.errors.code">
                <input
                  v-model="form.code"
                  type="text"
                  placeholder="VD: CK-2024-001"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] placeholder:text-gray-300"
                  :class="form.errors.code
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                />
              </FormField>

              <!-- Ngày chuyển -->
              <FormField label="Ngày chuyển" required :error="form.errors.transfer_date">
                <input
                  v-model="form.transfer_date"
                  type="date"
                  class="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow]"
                  :class="form.errors.transfer_date
                    ? 'border-red-400 bg-red-50/40 focus:border-red-400 focus:ring-2 focus:ring-red-100'
                    : 'border-gray-200 bg-white focus:border-primary-500 focus:ring-2 focus:ring-primary-100'"
                />
              </FormField>

              <!-- Kho nguồn -->
              <FormField label="Kho nguồn" required :error="form.errors.from_warehouse_id">
                <SearchableSelect
                  v-model="form.from_warehouse_id"
                  :options="warehouseOptions"
                  placeholder="— Chọn kho nguồn —"
                  :has-error="!!form.errors.from_warehouse_id"
                  @change="onFromWarehouseChange"
                />
              </FormField>

              <!-- Kho đích -->
              <FormField label="Kho đích" required :error="form.errors.to_warehouse_id">
                <SearchableSelect
                  v-model="form.to_warehouse_id"
                  :options="toWarehouseOptions"
                  placeholder="— Chọn kho đích —"
                  :has-error="!!form.errors.to_warehouse_id"
                />
              </FormField>
            </div>

            <FormField label="Ghi chú" optional>
              <textarea
                v-model="form.notes"
                rows="2"
                placeholder="Lý do chuyển kho, ghi chú..."
                class="w-full resize-none rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm outline-none transition-[border-color,box-shadow] placeholder:text-gray-300 focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
              />
            </FormField>
          </div>
        </div>

        <!-- ─── Section 2: Hàng hóa chuyển kho ─── -->
        <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
          <div class="flex items-center justify-between border-b border-gray-100 bg-gray-50/60 px-6 py-4">
            <div class="flex items-center gap-2.5">
              <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-100">
                <svg class="h-3.5 w-3.5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
              </div>
              <h2 class="text-sm font-semibold text-gray-800">Hàng hóa chuyển kho</h2>
              <span v-if="form.items.length"
                class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-600">
                {{ form.items.length }}
              </span>
            </div>
            <div class="flex items-center gap-3">
              <p v-if="!form.from_warehouse_id" class="text-xs text-amber-600">Chọn kho nguồn trước</p>
              <button
                type="button"
                @click="addRow"
                class="inline-flex items-center gap-1.5 rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-semibold text-primary-700 transition hover:bg-primary-100"
              >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4" />
                </svg>
                Thêm dòng
              </button>
            </div>
          </div>

          <!-- Empty state -->
          <div v-if="!form.items.length" class="flex flex-col items-center gap-2.5 px-6 py-14 text-center">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100">
              <svg class="h-7 w-7 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                  d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
              </svg>
            </div>
            <div>
              <p class="text-sm font-medium text-gray-600">Chưa có hàng hóa nào</p>
              <p class="mt-0.5 text-xs text-gray-400">Nhấn "+ Thêm dòng" để thêm sản phẩm cần chuyển.</p>
            </div>
          </div>

          <!-- Item cards -->
          <div v-else class="divide-y divide-gray-50">
            <div v-for="(item, index) in form.items" :key="index" class="p-5 space-y-4">

              <!-- Product + Qty row -->
              <div class="flex items-start gap-3">
                <div class="flex-1">
                  <label class="mb-1.5 block text-xs font-medium text-gray-600">Sản phẩm</label>
                  <ProductSearch
                    v-model="item.product_id"
                    :display-text="item._productDisplay"
                    @select="opt => onProductChange(index, opt)"
                    :has-error="!!form.errors[`items.${index}.product_id`]"
                  />
                  <p v-if="form.errors[`items.${index}.product_id`]"
                    class="mt-1.5 flex items-center gap-1 text-xs text-red-600">
                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd"
                        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                        clip-rule="evenodd" />
                    </svg>
                    {{ form.errors[`items.${index}.product_id`] }}
                  </p>
                </div>

                <div class="w-32 shrink-0">
                  <label class="mb-1.5 block text-xs font-medium text-gray-600">Số lượng</label>
                  <input
                    v-model.number="item.quantity"
                    type="number"
                    min="1"
                    @change="onQuantityChange(index)"
                    class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm text-right outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-100"
                    :class="form.errors[`items.${index}.quantity`] && 'border-red-400 bg-red-50/40'"
                  />
                  <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-xs text-red-600">
                    {{ form.errors[`items.${index}.quantity`] }}
                  </p>
                </div>

                <div class="pt-7">
                  <button
                    type="button"
                    @click="removeRow(index)"
                    class="rounded-lg p-1.5 text-gray-300 transition hover:bg-red-50 hover:text-red-500 focus:outline-none"
                    title="Xóa dòng"
                  >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Stock hint -->
              <div v-if="item.product_id && form.from_warehouse_id" class="text-xs text-gray-400">
                <span v-if="getProduct(item.product_id)?.has_serial">
                  Serial trong kho nguồn:
                  <strong class="text-gray-600">{{ getAvailableSerials(item.product_id).length }}</strong> chiếc
                </span>
              </div>

              <!-- Serial selection -->
              <div
                v-if="item.product_id && getProduct(item.product_id)?.has_serial && form.from_warehouse_id"
                class="rounded-xl border border-blue-100 bg-blue-50/60 p-4 space-y-3"
              >
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 3.5V16M4 16v.5M4 9v.5M4 6h.01" />
                    </svg>
                    <span class="text-sm font-semibold text-blue-800">Chọn Serial</span>
                    <span
                      class="rounded-full px-2 py-0.5 text-xs font-bold"
                      :class="item.serial_ids.length === item.quantity
                        ? 'bg-green-100 text-green-700'
                        : 'bg-amber-100 text-amber-700'"
                    >
                      {{ item.serial_ids.length }}/{{ item.quantity }}
                    </span>
                  </div>
                  <button
                    v-if="item.serial_ids.length > 0"
                    type="button"
                    @click="item.serial_ids = []"
                    class="text-xs text-gray-400 hover:text-red-500 transition"
                  >
                    Bỏ chọn tất cả
                  </button>
                </div>

                <div v-if="getAvailableSerials(item.product_id).length === 0"
                  class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
                  Không có serial nào trong kho nguồn cho sản phẩm này.
                </div>

                <div v-else class="flex flex-wrap gap-1.5 max-h-40 overflow-y-auto">
                  <button
                    v-for="s in getAvailableSerials(item.product_id)"
                    :key="s.id"
                    type="button"
                    @click="toggleSerial(index, s.id)"
                    :disabled="!item.serial_ids.includes(s.id) && item.serial_ids.length >= item.quantity"
                    class="rounded-lg border px-2.5 py-1 font-mono text-xs transition"
                    :class="item.serial_ids.includes(s.id)
                      ? 'bg-blue-600 text-white border-blue-600'
                      : item.serial_ids.length >= item.quantity
                        ? 'bg-gray-50 text-gray-400 border-gray-200 cursor-not-allowed'
                        : 'bg-white text-gray-700 border-blue-200 hover:border-blue-500'"
                  >
                    {{ s.serial_number }}
                  </button>
                </div>

                <p v-if="form.errors[`items.${index}.serial_ids`]" class="text-xs text-red-600">
                  {{ form.errors[`items.${index}.serial_ids`] }}
                </p>
              </div>
            </div>
          </div>

          <p v-if="form.errors.items"
            class="border-t border-red-100 bg-red-50 px-5 py-2.5 text-xs text-red-600">
            {{ form.errors.items }}
          </p>
        </div>

        <!-- Action bar -->
        <div class="flex items-center gap-3 pb-2">
          <button
            type="submit"
            :disabled="form.processing"
            class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            {{ form.processing ? 'Đang lưu...' : (isEdit ? 'Cập nhật phiếu' : 'Tạo phiếu chuyển kho') }}
          </button>
          <Link
            :href="route('warehouse.stock-transfers.index')"
            class="rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50"
          >
            Hủy
          </Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import FormField from '@/Components/Shared/FormField.vue';
import ProductSearch from '@/Components/Shared/ProductSearch.vue';
import SearchableSelect from '@/Components/Shared/SearchableSelect.vue';

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
    product_id:      i.product_id,
    quantity:        i.quantity,
    serial_ids:      i.serial_ids ?? [],
    _productDisplay: i.product_name ?? '',
    _unit:           i.product_unit ?? '',
  })) ?? [],
});

const toWarehouses = computed(() =>
  props.warehouses.filter(w => w.id !== form.from_warehouse_id)
);

const warehouseOptions = computed(() =>
  (props.warehouses ?? []).map(w => ({ value: w.id, label: w.name }))
);
const toWarehouseOptions = computed(() =>
  toWarehouses.value.map(w => ({ value: w.id, label: w.name }))
);

const getProduct = (productId) => props.products.find(p => p.id === productId);

const getAvailableSerials = (productId) => {
  const product = getProduct(productId);
  return product?.serials ?? [];
};

const onFromWarehouseChange = () => {
  if (form.to_warehouse_id === form.from_warehouse_id) {
    form.to_warehouse_id = '';
  }
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
  form.items.forEach(item => { item.serial_ids = []; });
};

const onProductChange = (index, opt) => {
  form.items[index].serial_ids = [];
  if (opt) {
    form.items[index]._productDisplay = opt.code ? `${opt.code} - ${opt.label}` : opt.label;
    form.items[index]._unit = opt.unit ?? opt.meta ?? '';
  }
};

const onQuantityChange = (index) => {
  const item = form.items[index];
  if (item.quantity < 1) item.quantity = 1;
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
  form.items.push({ product_id: null, quantity: 1, serial_ids: [], _productDisplay: '', _unit: '' });
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
