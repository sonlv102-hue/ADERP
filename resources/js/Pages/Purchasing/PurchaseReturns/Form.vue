<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('purchasing.purchase-returns.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ purchaseReturn ? 'Sửa phiếu trả hàng mua' : 'Tạo phiếu trả hàng mua' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- Header info -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã phiếu <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text"
                :disabled="!!purchaseReturn"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none disabled:bg-gray-100 disabled:text-gray-500"
                :class="{ 'border-red-500': form.errors.code }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ngày trả <span class="text-red-500">*</span></label>
              <input v-model="form.return_date" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.return_date }" />
              <p v-if="form.errors.return_date" class="mt-1 text-xs text-red-600">{{ form.errors.return_date }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Đơn mua hàng <span class="text-red-500">*</span></label>
              <select v-model="form.purchase_order_id" @change="onPoChange"
                :disabled="!!purchaseReturn"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none bg-white disabled:bg-gray-100 disabled:text-gray-500"
                :class="{ 'border-red-500': form.errors.purchase_order_id }">
                <option value="">-- Chọn đơn mua --</option>
                <option v-for="po in purchaseOrders" :key="po.id" :value="po.id">
                  {{ po.code }} — {{ po.supplier }}
                </option>
              </select>
              <p v-if="form.errors.purchase_order_id" class="mt-1 text-xs text-red-600">{{ form.errors.purchase_order_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Kho xuất hàng <span class="text-red-500">*</span></label>
              <select v-model="form.warehouse_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none bg-white"
                :class="{ 'border-red-500': form.errors.warehouse_id }">
                <option value="">-- Chọn kho --</option>
                <option v-for="wh in warehouses" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
              </select>
              <p v-if="form.errors.warehouse_id" class="mt-1 text-xs text-red-600">{{ form.errors.warehouse_id }}</p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Lý do trả hàng</label>
            <textarea v-model="form.reason" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>
        </div>

        <!-- Loading state -->
        <div v-if="loadingItems" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-400">
          Đang tải danh sách hàng hóa...
        </div>

        <!-- Items table -->
        <div v-if="poItems.length > 0" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-800">Hàng hóa trả lại</h2>
            <p class="text-xs text-gray-500 mt-0.5">Nhập số lượng trả, tối đa theo cột "Có thể trả"</p>
          </div>

          <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-5 py-3 font-semibold text-gray-600">Sản phẩm</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Đã nhận</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Đã trả</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Có thể trả</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Trả lần này</th>
                <th class="text-right px-5 py-3 font-semibold text-gray-600">Đơn giá</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <template v-for="(item, index) in form.items" :key="index">
                <tr :class="{ 'bg-gray-50 opacity-60': poItems[index].max_returnable === 0 }">
                  <td class="px-5 py-3">
                    <p class="font-medium text-gray-800">{{ poItems[index].product_name }}</p>
                    <p class="text-xs text-gray-400 font-mono">{{ poItems[index].product_code }}</p>
                  </td>
                  <td class="px-5 py-3 text-right text-gray-500">
                    {{ poItems[index].total_received.toLocaleString('vi-VN') }} {{ poItems[index].unit }}
                  </td>
                  <td class="px-5 py-3 text-right text-gray-500">
                    {{ poItems[index].prior_returned.toLocaleString('vi-VN') }}
                  </td>
                  <td class="px-5 py-3 text-right font-medium"
                    :class="poItems[index].max_returnable > 0 ? 'text-orange-600' : 'text-gray-400'">
                    {{ poItems[index].max_returnable.toLocaleString('vi-VN') }}
                  </td>
                  <td class="px-5 py-3 text-right">
                    <input v-model.number="item.quantity" type="number" min="0"
                      :max="poItems[index].max_returnable"
                      :disabled="poItems[index].max_returnable === 0"
                      @change="onQtyChange(index)"
                      class="w-24 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-right disabled:bg-gray-100"
                      :class="{ 'border-red-500': form.errors[`items.${index}.quantity`] }" />
                    <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-xs text-red-600 text-left">
                      {{ form.errors[`items.${index}.quantity`] }}
                    </p>
                  </td>
                  <td class="px-5 py-3 text-right">
                    <input v-model.number="item.unit_price" type="number" min="0" step="any"
                      class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-right" />
                  </td>
                </tr>

                <!-- Serial selection panel -->
                <tr v-if="poItems[index].has_serial && item.quantity > 0" class="bg-blue-50">
                  <td colspan="6" class="px-5 py-3">
                    <div class="space-y-2.5">
                      <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                          <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 3.5V16M4 16v.5M4 9v.5M4 6h.01M4 3h.01M12 3h.01M20 3h.01M20 6h.01M20 9h.01" />
                          </svg>
                          <span class="text-sm font-semibold text-blue-800">Serial cần trả</span>
                          <span class="px-2 py-0.5 rounded-full text-xs font-bold"
                            :class="item.serials.length === item.quantity ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
                            {{ item.serials.length }}/{{ item.quantity }}
                          </span>
                        </div>
                        <button v-if="item.serials.length > 0" type="button" @click="clearSerials(index)"
                          class="text-xs text-gray-400 hover:text-red-500">Xóa tất cả</button>
                      </div>

                      <div v-if="item.serials.length < item.quantity" class="flex gap-2 items-center">
                        <input
                          :ref="el => { if (el) scanRefs[index] = el }"
                          v-model="scanInputs[index]"
                          @keydown.enter.prevent="addSerial(index)"
                          type="text"
                          placeholder="Nhập serial cần trả → Enter"
                          autocomplete="off"
                          class="flex-1 max-w-sm px-3 py-2 border border-blue-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-400 outline-none"
                          :class="{ 'border-red-400 bg-red-50': scanErrors[index] }" />
                        <button type="button" @click="addSerial(index)"
                          class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Thêm</button>
                      </div>

                      <p v-if="scanErrors[index]" class="text-xs text-red-600 font-medium">{{ scanErrors[index] }}</p>

                      <div v-if="item.serials.length > 0" class="flex flex-wrap gap-1.5">
                        <div v-for="(serial, si) in item.serials" :key="si"
                          class="group flex items-center gap-1 px-2.5 py-1 bg-white border border-blue-200 rounded-lg font-mono text-xs text-gray-800 hover:border-red-300">
                          <span>{{ serial }}</span>
                          <button type="button" @click="removeSerial(index, si)"
                            class="text-gray-300 group-hover:text-red-500 ml-0.5 leading-none">×</button>
                        </div>
                      </div>

                      <p v-if="form.errors[`items.${index}.serials`]" class="text-xs text-red-600">
                        {{ form.errors[`items.${index}.serials`] }}
                      </p>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
          <p v-if="form.errors.items" class="px-5 py-2 text-xs text-red-600">{{ form.errors.items }}</p>
        </div>

        <div v-if="form.purchase_order_id && !loadingItems && poItems.length === 0"
          class="bg-amber-50 border border-amber-200 rounded-xl p-5 text-amber-700 text-sm">
          Đơn mua này chưa có hàng hóa nào đã nhận hoặc tất cả đã được trả hết.
        </div>

        <div class="flex gap-3">
          <button type="submit"
            :disabled="form.processing || returningCount === 0"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : (purchaseReturn ? `Cập nhật (${returningCount} loại)` : `Tạo phiếu trả (${returningCount} loại)`) }}
          </button>
          <Link :href="purchaseReturn ? route('purchasing.purchase-returns.show', purchaseReturn.id) : route('purchasing.purchase-returns.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref, nextTick } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  nextCode: String,
  purchaseOrders: Array,
  warehouses: Array,
  purchaseReturn: Object,
});

const today = new Date().toISOString().slice(0, 10);
const poItems = ref([]);
const loadingItems = ref(false);
const scanInputs = reactive({});
const scanErrors = reactive({});
const scanRefs = {};

const form = useForm({
  code:              props.purchaseReturn?.code              ?? props.nextCode ?? '',
  purchase_order_id: props.purchaseReturn?.purchase_order_id ?? '',
  warehouse_id:      props.purchaseReturn?.warehouse_id      ?? '',
  return_date:       props.purchaseReturn?.return_date       ?? today,
  reason:            props.purchaseReturn?.reason            ?? '',
  notes:             props.purchaseReturn?.notes             ?? '',
  items: [],
});

const loadPoItems = async (preItems = null) => {
  poItems.value = [];
  form.items = [];
  if (!form.purchase_order_id) return;

  loadingItems.value = true;
  try {
    const res = await fetch(route('purchasing.purchase-returns.po-items', form.purchase_order_id));
    const data = await res.json();
    poItems.value = data;
    form.items = data.map(item => {
      const existing = preItems?.find(pi => pi.purchase_order_item_id === item.id);
      return {
        purchase_order_item_id: item.id,
        product_id: item.product_id,
        quantity:   existing?.quantity   ?? 0,
        unit_price: existing?.unit_price ?? item.unit_price ?? null,
        serials:    existing?.serials    ?? [],
      };
    });
  } catch {
    poItems.value = [];
    form.items = [];
  } finally {
    loadingItems.value = false;
  }
};

const onPoChange = async () => {
  const selectedPo = props.purchaseOrders.find(po => po.id === form.purchase_order_id);
  if (selectedPo) form.warehouse_id = selectedPo.warehouse_id ?? '';
  await loadPoItems();
};

onMounted(() => {
  if (props.purchaseReturn) {
    loadPoItems(props.purchaseReturn.items);
  }
});

const onQtyChange = (index) => {
  const item = form.items[index];
  const max = poItems.value[index].max_returnable;
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
    scanErrors[index] = `Serial "${val}" đã được nhập.`;
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
};

const returningCount = computed(() => form.items.filter(i => i.quantity > 0).length);

const submit = () => {
  const originalItems = form.items;
  form.items = form.items.filter(i => i.quantity > 0);

  if (props.purchaseReturn) {
    form.put(route('purchasing.purchase-returns.update', props.purchaseReturn.id), {
      onError: () => { form.items = originalItems; },
    });
  } else {
    form.post(route('purchasing.purchase-returns.store'), {
      onError: () => { form.items = originalItems; },
    });
  }
};
</script>
