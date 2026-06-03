<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('purchasing.purchase-orders.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ purchaseOrder ? 'Sửa đơn mua hàng' : 'Tạo đơn mua hàng' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <!-- Thông tin chung -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã đơn <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text"
                :disabled="!!purchaseOrder"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none disabled:bg-gray-100 disabled:text-gray-500"
                :class="{ 'border-red-500': form.errors.code }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày đặt <span class="text-red-500">*</span></label>
              <input v-model="form.order_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.order_date }" />
              <p v-if="form.errors.order_date" class="mt-1 text-xs text-red-600">{{ form.errors.order_date }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nhà cung cấp <span class="text-red-500">*</span></label>
              <select v-model="form.supplier_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.supplier_id }">
                <option value="">-- Chọn nhà cung cấp --</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.code }} - {{ s.name }}</option>
              </select>
              <p v-if="form.errors.supplier_id" class="mt-1 text-xs text-red-600">{{ form.errors.supplier_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Kho nhận hàng <span class="text-red-500">*</span></label>
              <select v-model="form.warehouse_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.warehouse_id }">
                <option value="">-- Chọn kho --</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
              </select>
              <p v-if="form.errors.warehouse_id" class="mt-1 text-xs text-red-600">{{ form.errors.warehouse_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày dự kiến nhận</label>
              <input v-model="form.expected_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.expected_date }" />
              <p v-if="form.errors.expected_date" class="mt-1 text-xs text-red-600">{{ form.errors.expected_date }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Loại hóa đơn đầu vào</label>
              <select v-model="form.invoice_type"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none bg-white">
                <option v-for="t in invoiceTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Dự án liên kết</label>
              <select v-model="form.project_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none bg-white">
                <option :value="null">-- Không liên kết dự án --</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
              <textarea v-model="form.notes" rows="2"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
          </div>
        </div>

        <!-- Chi tiết hàng hóa -->
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
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Thành tiền</th>
                <th class="px-5 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(item, index) in form.items" :key="index">
                <td class="px-5 py-3">
                  <ProductSearch
                    :options="products"
                    v-model="item.product_id"
                    @select="onProductSelect(index, $event)"
                    :has-error="false"
                  />
                </td>
                <td class="px-5 py-3">
                  <input :value="itemUnit(item.product_id)" type="text" readonly
                    class="w-24 px-3 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 outline-none" />
                </td>
                <td class="px-5 py-3">
                  <input v-model.number="item.quantity" type="number" min="1"
                    class="w-24 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
                </td>
                <td class="px-5 py-3">
                  <input v-model.number="item.unit_price" type="number" min="0"
                    class="w-36 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
                </td>
                <td class="px-5 py-3 text-right text-gray-700 font-medium">
                  {{ formatVnd(item.quantity * item.unit_price) }}
                </td>
                <td class="px-5 py-3 text-right">
                  <button type="button" @click="removeRow(index)" class="text-red-500 hover:text-red-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </td>
              </tr>
              <tr v-if="!form.items.length">
                <td colspan="6" class="px-5 py-8 text-center text-gray-400">Nhấn "Thêm dòng" để thêm hàng hóa</td>
              </tr>
            </tbody>
            <tfoot v-if="form.items.length" class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td colspan="4" class="px-5 py-3 text-right font-semibold text-gray-700">Tổng cộng:</td>
                <td class="px-5 py-3 text-right font-bold text-gray-900">{{ formatVnd(grandTotal) }}</td>
                <td />
              </tr>
            </tfoot>
          </table>
          <p v-if="form.errors.items" class="px-5 py-2 text-xs text-red-600">{{ form.errors.items }}</p>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (purchaseOrder ? 'Cập nhật' : 'Tạo đơn mua hàng') }}
          </button>
          <Link :href="purchaseOrder ? route('purchasing.purchase-orders.show', purchaseOrder.id) : route('purchasing.purchase-orders.index')"
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
import ProductSearch from '@/Components/Shared/ProductSearch.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  nextCode: String,
  suppliers: Array,
  warehouses: Array,
  products: Array,
  projects: { type: Array, default: () => [] },
  invoiceTypes: Array,
  purchaseOrder: Object,
});

const { formatVnd } = useCurrency();

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  code:          props.purchaseOrder?.code          ?? props.nextCode ?? '',
  supplier_id:   props.purchaseOrder?.supplier_id   ?? '',
  warehouse_id:  props.purchaseOrder?.warehouse_id  ?? '',
  project_id:    props.purchaseOrder?.project_id    ?? null,
  order_date:    props.purchaseOrder?.order_date     ?? today,
  expected_date: props.purchaseOrder?.expected_date  ?? '',
  notes:         props.purchaseOrder?.notes          ?? '',
  invoice_type:  props.purchaseOrder?.invoice_type   ?? 'vat',
  items:         props.purchaseOrder?.items          ?? [],
});

const addRow = () => {
  form.items.push({ product_id: '', quantity: 1, unit_price: 0 });
};

const removeRow = (index) => {
  form.items.splice(index, 1);
};

const onProductSelect = (index, product) => {
  if (product) form.items[index].unit_price = product.cost_price ?? 0;
};

const itemUnit = (productId) => props.products.find(p => p.id === productId)?.unit ?? '';

const grandTotal = computed(() =>
  form.items.reduce((sum, item) => sum + item.quantity * item.unit_price, 0)
);

const submit = () => {
  if (props.purchaseOrder) {
    form.put(route('purchasing.purchase-orders.update', props.purchaseOrder.id));
  } else {
    form.post(route('purchasing.purchase-orders.store'));
  }
};
</script>
