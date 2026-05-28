<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="order ? route('sales.orders.show', order.id) : route('sales.orders.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ order ? 'Sửa đơn hàng ' + order.code : 'Tạo đơn hàng' }}</h1>
      </div>

      <!-- Banner đơn bổ sung -->
      <div v-if="supplementaryFor" class="flex items-center gap-3 px-4 py-3 bg-orange-50 border border-orange-200 rounded-xl text-sm">
        <svg class="w-4 h-4 text-orange-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="text-orange-800">
          Đây là <strong>đơn bổ sung</strong> cho đơn <strong>{{ supplementaryFor.code }}</strong>
          ({{ supplementaryFor.customer_name }}). Khi đơn này hoàn thành, cảnh báo xuất kho vượt sẽ tự động được giải quyết.
        </span>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã đơn hàng <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text" :readonly="!!order"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.code, 'bg-gray-50 text-gray-500': !!order }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Khách hàng <span class="text-red-500">*</span></label>
              <select v-model="form.customer_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.customer_id }">
                <option value="">-- Chọn khách hàng --</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
              </select>
              <p v-if="form.errors.customer_id" class="mt-1 text-xs text-red-600">{{ form.errors.customer_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày đặt hàng <span class="text-red-500">*</span></label>
              <input v-model="form.order_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.order_date }" />
              <p v-if="form.errors.order_date" class="mt-1 text-xs text-red-600">{{ form.errors.order_date }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày giao hàng dự kiến</label>
              <input v-model="form.expected_delivery" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>
          </div>

          <!-- Báo giá liên kết -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Báo giá</label>
            <select v-model="form.quotation_id" @change="onQuotationChange"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none">
              <option :value="null">-- Chọn báo giá (tuỳ chọn) --</option>
              <option v-for="q in quotations" :key="q.id" :value="q.id">{{ q.code }}</option>
            </select>
            <p v-if="form.quotation_id" class="mt-1 text-xs text-green-600">
              Đã liên kết báo giá — khách hàng và danh sách hàng hoá đã được điền tự động.
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>
        </div>

        <!-- Items -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-800">Chi tiết hàng hóa / dịch vụ</h2>
            <div class="flex gap-2">
              <button type="button" @click="addRow('product')"
                class="bg-primary-600 hover:bg-primary-700 text-white px-3 py-2 rounded-lg text-sm font-medium">+ Sản phẩm</button>
              <button type="button" @click="addRow('service')"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-sm font-medium">+ Dịch vụ</button>
            </div>
          </div>

          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-4 py-3 font-semibold text-gray-600">Sản phẩm/Dịch vụ</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 w-20">ĐVT</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 w-24">SL</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 w-32">Đơn giá</th>
                <th class="text-right px-4 py-3 font-semibold text-gray-600 w-32">Thành tiền</th>
                <th class="w-10 px-4 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(item, idx) in form.items" :key="idx">
                <td class="px-4 py-3">
                  <template v-if="item._type === 'product'">
                    <select v-model="item.product_id" @change="onItemChange(idx)"
                      class="w-full px-2 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-xs">
                      <option value="">-- Chọn sản phẩm --</option>
                      <option v-for="p in products" :key="p.id" :value="p.id">{{ p.code }} - {{ p.name }}</option>
                    </select>
                  </template>
                  <template v-else>
                    <select v-model="item.service_id" @change="onItemChange(idx)"
                      class="w-full px-2 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-xs">
                      <option value="">-- Chọn dịch vụ --</option>
                      <option v-for="s in services" :key="s.id" :value="s.id">{{ s.code }} - {{ s.name }}</option>
                    </select>
                  </template>
                </td>
                <td class="px-4 py-3">
                  <input :value="item.unit" type="text" readonly
                    class="w-full px-2 py-1.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 outline-none text-xs" />
                </td>
                <td class="px-4 py-3">
                  <input v-model.number="item.quantity" type="number" min="1" step="1"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-xs" />
                </td>
                <td class="px-4 py-3">
                  <input v-model.number="item.unit_price" type="number" min="0"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-xs" />
                </td>
                <td class="px-4 py-3 text-right font-medium text-gray-700 text-xs">
                  {{ formatVnd(item.quantity * item.unit_price) }}
                </td>
                <td class="px-4 py-3 text-center">
                  <button type="button" @click="removeRow(idx)" class="text-red-500 hover:text-red-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </td>
              </tr>
              <tr v-if="!form.items.length">
                <td colspan="6" class="px-5 py-8 text-center text-gray-400">Chưa có hàng hóa. Nhấn "+ Sản phẩm" hoặc "+ Dịch vụ".</td>
              </tr>
            </tbody>
            <tfoot v-if="form.items.length" class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td colspan="4" class="px-4 py-3 text-right font-bold text-gray-800">Tổng cộng:</td>
                <td class="px-4 py-3 text-right font-bold text-primary-700 text-base">{{ formatVnd(grandTotal) }}</td>
                <td />
              </tr>
            </tfoot>
          </table>
          <p v-if="form.errors.items" class="px-5 py-2 text-xs text-red-600">{{ form.errors.items }}</p>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (order ? 'Cập nhật đơn hàng' : 'Tạo đơn hàng') }}
          </button>
          <Link :href="order ? route('sales.orders.show', order.id) : route('sales.orders.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  nextCode:        String,
  order:           Object,
  customers:       Array,
  products:        Array,
  services:        Array,
  quotations:      Array,
  fromQuotationId: { type: [Number, String], default: null },
  supplementaryFor: { type: Object, default: null },
});

const form = useForm({
  code:                       props.order?.code ?? props.nextCode ?? '',
  customer_id:                props.order?.customer_id ?? props.supplementaryFor?.customer_id ?? '',
  quotation_id:               props.order?.quotation_id ?? null,
  supplementary_for_order_id: props.order ? null : (props.supplementaryFor?.id ?? null),
  order_date:                 props.order?.order_date ?? new Date().toISOString().slice(0, 10),
  expected_delivery:          props.order?.expected_delivery ?? '',
  notes:                      props.order?.notes ?? '',
  items:                      props.order?.items ? props.order.items.map(i => ({ ...i })) : [],
});

const onQuotationChange = () => {
  if (!form.quotation_id) return;
  const q = props.quotations.find(q => q.id === form.quotation_id);
  if (!q) return;
  form.customer_id = q.customer_id;
  form.items = q.items.map(i => ({ ...i }));
};

onMounted(() => {
  if (props.fromQuotationId && !props.order) {
    form.quotation_id = Number(props.fromQuotationId);
    onQuotationChange();
  }
});

const addRow = (type) => {
  form.items.push({
    _type:      type,
    product_id: type === 'product' ? '' : null,
    service_id: type === 'service' ? '' : null,
    name:       '',
    unit:       '',
    quantity:   1,
    unit_price: 0,
  });
};

const removeRow = (idx) => form.items.splice(idx, 1);

const onItemChange = (idx) => {
  const item = form.items[idx];
  if (item._type === 'product') {
    const p = props.products.find(p => p.id === item.product_id);
    if (p) { item.name = p.name; item.unit = p.unit ?? ''; item.unit_price = p.sell_price ?? 0; }
  } else {
    const s = props.services.find(s => s.id === item.service_id);
    if (s) { item.name = s.name; item.unit = s.unit ?? ''; item.unit_price = s.price ?? 0; }
  }
};

const grandTotal = computed(() =>
  form.items.reduce((s, i) => s + (i.quantity * i.unit_price), 0)
);

const { formatVnd } = useCurrency();

const submit = () => {
  const payload = form.items.map(({ _type, ...rest }) => rest);
  if (props.order) {
    form.transform(data => ({ ...data, items: payload })).put(route('sales.orders.update', props.order.id));
  } else {
    form.transform(data => ({ ...data, items: payload })).post(route('sales.orders.store'));
  }
};
</script>
