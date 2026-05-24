<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('purchasing.purchase-orders.show', purchaseOrder.id)" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ entry ? 'Sửa phiếu nhập kho' : 'Nhập kho từ đơn mua' }}</h1>
      </div>

      <!-- Contract warning banner -->
      <div v-if="!hasPurchaseContract" class="flex items-start gap-3 bg-amber-50 border border-amber-300 rounded-xl px-5 py-4 mb-2">
        <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <div class="flex-1">
          <p class="text-sm font-semibold text-amber-800">Đơn mua chưa có hợp đồng mua</p>
          <p class="text-xs text-amber-700 mt-0.5">
            Đề nghị tạo hợp đồng mua trước khi nhập kho để đảm bảo đầy đủ chứng từ.
            <Link :href="route('purchasing.purchase-contracts.create')" class="underline font-medium ml-1">Tạo hợp đồng →</Link>
          </p>
        </div>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- PO context header (locked) -->
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-5">
          <p class="text-xs font-semibold text-blue-500 uppercase tracking-wide mb-3">Đơn mua hàng</p>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
            <div>
              <span class="text-blue-600">Mã đơn</span>
              <p class="font-semibold text-blue-900 mt-0.5">{{ purchaseOrder.code }}</p>
            </div>
            <div>
              <span class="text-blue-600">Nhà cung cấp</span>
              <p class="font-medium text-blue-900 mt-0.5">{{ purchaseOrder.supplier }}</p>
            </div>
            <div>
              <span class="text-blue-600">Kho nhập</span>
              <p class="font-medium text-blue-900 mt-0.5">{{ purchaseOrder.warehouse }}</p>
            </div>
            <div>
              <span class="text-blue-600">Tổng sản phẩm</span>
              <p class="font-medium text-blue-900 mt-0.5">{{ purchaseOrder.items.length }} loại</p>
            </div>
          </div>
        </div>

        <!-- Basic info -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã phiếu <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text" :readonly="!!entry"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.code, 'bg-gray-50 cursor-not-allowed': entry }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày nhập <span class="text-red-500">*</span></label>
              <input v-model="form.entry_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.entry_date }" />
              <p v-if="form.errors.entry_date" class="mt-1 text-xs text-red-600">{{ form.errors.entry_date }}</p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>
        </div>

        <!-- Items (locked to PO) -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-800">Chi tiết hàng hóa nhận</h2>
            <p class="text-xs text-gray-500 mt-0.5">Sản phẩm và số lượng giới hạn theo đơn mua hàng</p>
          </div>

          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Sản phẩm</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Đặt</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Đã nhận</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Còn lại</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Nhận lần này</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Đơn giá</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Thành tiền</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <template v-for="(item, index) in form.items" :key="index">
                <tr :class="{ 'bg-gray-50': item.quantity === 0 }">
                  <td class="px-5 py-3">
                    <p class="font-medium text-gray-800">{{ poItems[index].product_name }}</p>
                    <p class="text-xs text-gray-400 font-mono">{{ poItems[index].product_code }}</p>
                  </td>
                  <td class="px-5 py-3 text-right text-gray-500">
                    {{ poItems[index].ordered_qty.toLocaleString('vi-VN') }} {{ poItems[index].unit }}
                  </td>
                  <td class="px-5 py-3 text-right text-gray-500">
                    {{ poItems[index].received_qty.toLocaleString('vi-VN') }}
                  </td>
                  <td class="px-5 py-3 text-right font-medium text-orange-600">
                    {{ poItems[index].remaining_qty.toLocaleString('vi-VN') }}
                  </td>
                  <td class="px-5 py-3 text-right">
                    <input v-model.number="item.quantity" type="number" min="0"
                      :max="poItems[index].remaining_qty"
                      @change="onQuantityChange(index)"
                      class="w-24 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-right"
                      :class="{ 'border-red-500': form.errors[`items.${index}.quantity`] }" />
                    <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-xs text-red-600 text-left">
                      {{ form.errors[`items.${index}.quantity`] }}
                    </p>
                  </td>
                  <td class="px-5 py-3 text-right">
                    <input v-model.number="item.unit_price" type="number" min="0"
                      class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-right"
                      :class="{ 'border-red-500': form.errors[`items.${index}.unit_price`] }" />
                  </td>
                  <td class="px-5 py-3 text-right font-medium text-gray-900">
                    {{ formatVnd(item.quantity * item.unit_price) }}
                  </td>
                </tr>

                <!-- Serial scan panel -->
                <tr v-if="item.quantity > 0" class="bg-blue-50">
                  <td colspan="7" class="px-5 py-3">
                    <div class="space-y-2.5">
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                          <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 3.5V16M4 16v.5M4 9v.5M4 6h.01M4 3h.01M12 3h.01M20 3h.01M20 6h.01M20 9h.01" />
                          </svg>
                          <span class="text-sm font-semibold text-blue-800">Kiểm soát Serial</span>
                          <span class="px-2 py-0.5 rounded-full text-xs font-bold"
                            :class="item.serials.length === item.quantity ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
                            {{ item.serials.length }}/{{ item.quantity }}
                          </span>
                          <span v-if="item.serials.length === item.quantity" class="text-xs text-green-600 font-medium">✓ Đủ serial</span>
                        </div>
                        <button v-if="item.serials.length > 0" type="button" @click="clearSerials(index)"
                          class="text-xs text-gray-400 hover:text-red-500 transition-colors">Xóa tất cả</button>
                      </div>

                      <div v-if="item.serials.length < item.quantity" class="flex gap-2 items-center">
                        <div class="relative flex-1 max-w-sm">
                          <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-400 pointer-events-none"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 3.5V16M4 16v.5M4 9v.5M4 6h.01M4 3h.01M12 3h.01M20 3h.01M20 6h.01M20 9h.01" />
                          </svg>
                          <input
                            :ref="el => { if (el) scanRefs[index] = el }"
                            v-model="scanInputs[index]"
                            @keydown.enter.prevent="addSerial(index)"
                            @paste="handlePaste(index, $event)"
                            type="text"
                            placeholder="Quét mã hoặc nhập serial → Enter"
                            autocomplete="off"
                            class="w-full pl-9 pr-3 py-2 border border-blue-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-400 outline-none bg-white placeholder-blue-300"
                            :class="{ 'border-red-400 bg-red-50': scanErrors[index] }" />
                        </div>
                        <button type="button" @click="addSerial(index)"
                          class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium whitespace-nowrap">Thêm</button>
                        <span class="text-xs text-gray-400">hoặc paste nhiều dòng</span>
                      </div>

                      <p v-if="scanErrors[index]" class="text-xs text-red-600 font-medium">⚠ {{ scanErrors[index] }}</p>

                      <div v-if="item.serials.length > 0" class="flex flex-wrap gap-1.5">
                        <div v-for="(serial, si) in item.serials" :key="si"
                          class="group flex items-center gap-1 px-2.5 py-1 bg-white border border-blue-200 rounded-lg font-mono text-xs text-gray-800 shadow-sm hover:border-red-300 transition-colors">
                          <span>{{ serial }}</span>
                          <button type="button" @click="removeSerial(index, si)"
                            class="text-gray-300 group-hover:text-red-500 transition-colors ml-0.5 leading-none">×</button>
                        </div>
                      </div>

                      <p v-if="!item.serials.length" class="text-xs text-blue-400 italic">
                        Chưa có serial nào. Dùng máy quét hoặc nhập thủ công bên trên.
                      </p>

                      <p v-if="form.errors[`items.${index}.serials`]" class="text-xs text-red-600">
                        {{ form.errors[`items.${index}.serials`] }}
                      </p>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
            <tfoot class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td colspan="6" class="px-5 py-3 text-right font-semibold text-gray-700">Tổng cộng:</td>
                <td class="px-5 py-3 text-right font-bold text-gray-900">{{ formatVnd(grandTotal) }}</td>
              </tr>
            </tfoot>
          </table>
          <p v-if="form.errors.items" class="px-5 py-2 text-xs text-red-600">{{ form.errors.items }}</p>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing || receivingCount === 0"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : entry ? `Cập nhật phiếu nhập (${receivingCount} loại)` : `Tạo phiếu nhập (${receivingCount} loại)` }}
          </button>
          <Link :href="route('purchasing.purchase-orders.show', purchaseOrder.id)"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, reactive, nextTick } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  nextCode: String,
  hasPurchaseContract: Boolean,
  purchaseOrder: Object,
  entry: { type: Object, default: null },
});

const { formatVnd } = useCurrency();

const poItems = props.purchaseOrder.items;
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  purchase_order_id: props.purchaseOrder.id,
  code:       props.nextCode ?? '',
  entry_date: props.entry?.entry_date ?? today,
  notes:      props.entry?.notes ?? '',
  items: poItems.map(item => {
    if (props.entry) {
      const ei = props.entry.items.find(e => e.product_id === item.product_id);
      return {
        product_id: item.product_id,
        quantity:   ei?.quantity   ?? 0,
        unit_price: ei?.unit_price ?? item.unit_price,
        serials:    ei?.serials    ?? [],
      };
    }
    return {
      product_id: item.product_id,
      quantity:   item.remaining_qty,
      unit_price: item.unit_price,
      serials:    [],
    };
  }),
});

const scanInputs = reactive({});
const scanErrors = reactive({});
const scanRefs = {};

const onQuantityChange = (index) => {
  const item = form.items[index];
  const max = poItems[index].remaining_qty;
  if (item.quantity > max) item.quantity = max;
  if (item.quantity < 0) item.quantity = 0;
  if (item.serials.length > item.quantity) item.serials.splice(item.quantity);
};

const addSerial = (index) => {
  const val = (scanInputs[index] ?? '').trim();
  if (!val) return;
  const item = form.items[index];
  if (item.serials.length >= item.quantity) {
    scanErrors[index] = `Đã đủ ${item.quantity} serial.`;
    return;
  }
  if (item.serials.includes(val)) {
    scanErrors[index] = `Serial "${val}" đã được nhập trong dòng này.`;
    return;
  }
  const otherSerials = form.items.flatMap((it, i) => i !== index ? it.serials : []);
  if (otherSerials.includes(val)) {
    scanErrors[index] = `Serial "${val}" đã tồn tại ở dòng khác trong phiếu.`;
    return;
  }
  item.serials.push(val);
  scanInputs[index] = '';
  scanErrors[index] = '';
  nextTick(() => scanRefs[index]?.focus());
};

const removeSerial = (index, si) => {
  form.items[index].serials.splice(si, 1);
  scanErrors[index] = '';
};

const clearSerials = (index) => {
  form.items[index].serials = [];
  scanInputs[index] = '';
  scanErrors[index] = '';
  nextTick(() => scanRefs[index]?.focus());
};

const handlePaste = (index, event) => {
  const text = event.clipboardData.getData('text');
  const lines = text.split(/[\r\n]+/).map(l => l.trim()).filter(Boolean);
  if (lines.length <= 1) return;
  event.preventDefault();
  const item = form.items[index];
  const otherSerials = form.items.flatMap((it, i) => i !== index ? it.serials : []);
  const duplicates = [];
  for (const line of lines) {
    if (item.serials.length >= item.quantity) break;
    if (item.serials.includes(line) || otherSerials.includes(line)) {
      duplicates.push(line);
      continue;
    }
    item.serials.push(line);
  }
  scanInputs[index] = '';
  scanErrors[index] = duplicates.length
    ? `Bỏ qua ${duplicates.length} serial trùng: ${duplicates.slice(0, 3).join(', ')}${duplicates.length > 3 ? '...' : ''}`
    : '';
};

const grandTotal = computed(() =>
  form.items.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0)
);

const receivingCount = computed(() => form.items.filter(item => item.quantity > 0).length);

const submit = () => {
  const originalItems = form.items;
  form.items = form.items.filter(item => item.quantity > 0);
  const options = { onError: () => { form.items = originalItems; } };
  if (props.entry) {
    form.put(route('warehouse.stock-entries.update', props.entry.id), options);
  } else {
    form.post(route('warehouse.stock-entries.store'), options);
  }
};
</script>
