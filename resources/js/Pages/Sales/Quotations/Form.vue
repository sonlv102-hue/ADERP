<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('sales.quotations.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">{{ isEdit ? 'Sửa báo giá' : 'Tạo báo giá' }}</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <!-- Header info -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Mã báo giá <span class="text-red-500">*</span></label>
              <input v-model="form.code" type="text" :readonly="isEdit"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                :class="{ 'border-red-500': form.errors.code, 'bg-gray-50': isEdit }" />
              <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Khách hàng <span class="text-red-500">*</span></label>
              <RemoteSearchSelect
                v-model="form.customer_id"
                :search-url="route('search.customers')"
                :display-text="initialCustomerDisplay"
                placeholder="-- Tìm khách hàng --"
                :has-error="!!form.errors.customer_id"
              />
              <p v-if="form.errors.customer_id" class="mt-1 text-xs text-red-600">{{ form.errors.customer_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Hiệu lực đến</label>
              <input v-model="form.valid_until" type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tổng tiền bán (tính CK tự động)</label>
              <div class="flex gap-2">
                <input v-model.number="desiredTotal" @input="calculateDiscountFromTotal" type="number" min="0" step="any"
                  class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                  placeholder="Nhập tổng tiền muốn bán, hệ thống sẽ tính % CK" />
                <select v-model="roundingMode" class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-sm">
                  <option value="">Không làm tròn</option>
                  <option value="1000">Làm tròn 1K</option>
                  <option value="10000">Làm tròn 10K</option>
                  <option value="100000">Làm tròn 100K</option>
                  <option value="1000000">Làm tròn 1M</option>
                </select>
                <button type="button" @click="roundPrice" v-if="roundingMode"
                  class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
                  Làm tròn
                </button>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Chiết khấu tổng</label>
              <div class="space-y-2">
                <div class="flex gap-2">
                  <select v-model="form.discount_type"
                    class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none">
                    <option value="percent">% (Phần trăm)</option>
                    <option value="fixed">Tiền (VND)</option>
                  </select>
                  <input v-model.number="form.discount_value" type="number" min="0" step="0.01"
                    class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                    :class="{ 'border-red-500': form.errors.discount_value }"
                    @input="updateDiscountType('manual')" />
                  <span v-if="form.discount_type === 'percent'" class="px-3 py-2 text-gray-600 bg-gray-50 rounded-lg">%</span>
                </div>
                <div v-if="form.discount_type === 'fixed'" class="text-xs text-blue-600">
                  → Tự động tính: <strong>{{ calculatedDiscountPercent.toFixed(2) }}%</strong> chiết khấu
                </div>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Hoặc nhập số tiền chiết khấu → Tính % tự động</label>
              <div class="flex gap-2">
                <input v-model.number="discountAmountInput" type="number" min="0" step="any"
                  placeholder="Nhập số tiền chiết khấu (VND), hệ thống sẽ tính %"
                  @input="calculateDiscountPercent"
                  class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
                <span class="px-3 py-2 text-gray-600 bg-gray-50 rounded-lg">đ</span>
              </div>
              <p v-if="discountAmountInput > 0" class="mt-1 text-xs text-blue-600">
                💡 Số tiền {{ formatVnd(discountAmountInput) }} = <strong>{{ calculatedDiscountPercent.toFixed(2) }}%</strong> chiết khấu
              </p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Bảng giá</label>
            <select v-model="selectedPriceList" @change="applyPriceList"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none">
              <option value="">-- Chọn bảng giá --</option>
              <option v-for="pl in priceLists" :key="pl.id" :value="pl.id">{{ pl.code }} - {{ pl.name }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
            <textarea v-model="form.notes" rows="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>
        </div>

        <!-- Items -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-800">Chi tiết hàng hóa / dịch vụ</h2>
            <div class="flex gap-2">
              <button type="button" @click="addRow('product')"
                class="bg-primary-600 hover:bg-primary-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
                + Sản phẩm
              </button>
              <button type="button" @click="addRow('service')"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
                + Dịch vụ
              </button>
            </div>
          </div>

          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="text-left px-4 py-3 font-semibold text-gray-600">Sản phẩm/Dịch vụ</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 w-20">ĐVT</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 w-24">SL</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 w-32">Đơn giá</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 w-24">VAT (%)</th>
                <th class="text-left px-4 py-3 font-semibold text-gray-600 w-28">CK (VND)</th>
                <th class="text-right px-4 py-3 font-semibold text-gray-600 w-32">Thành tiền</th>
                <th class="w-10 px-4 py-3" />
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(item, idx) in form.items" :key="idx">
                <td class="px-4 py-3">
                  <template v-if="item.item_type === 'product'">
                    <ProductSearch
                      v-model="item.product_id"
                      :display-text="item._productDisplay"
                      @select="opt => onProductSelect(idx, opt)"
                    />
                  </template>
                  <template v-else>
                    <RemoteSearchSelect
                      v-model="item.service_id"
                      :search-url="route('search.services')"
                      :display-text="item._serviceDisplay"
                      placeholder="-- Tìm dịch vụ --"
                      @change="opt => onServiceSelect(idx, opt)"
                    />
                  </template>
                </td>
                <td class="px-4 py-3">
                  <input :value="item.unit" type="text" readonly
                    class="w-full px-2 py-1.5 border border-gray-200 rounded-lg bg-gray-50 text-gray-500 outline-none text-xs" />
                </td>
                <td class="px-4 py-3">
                  <input v-model.number="item.quantity" type="number" min="1" step="any"
                    @input="recalcCkPercent(idx)"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-xs" />
                </td>
                <td class="px-4 py-3">
                  <input v-model.number="item.unit_price" type="number" min="0" step="any"
                    @input="recalcCkPercent(idx)"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-xs" />
                </td>
                <td class="px-4 py-3">
                  <select v-model.number="item.vat_rate"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none bg-white text-xs">
                    <option :value="null">—</option>
                    <option :value="0">0%</option>
                    <option :value="5">5%</option>
                    <option :value="8">8%</option>
                    <option :value="10">10%</option>
                  </select>
                </td>
                <td class="px-4 py-3">
                  <input v-model.number="item._ck_amount" type="number" min="0" step="any"
                    @input="onCkAmountChange(idx)"
                    placeholder="0"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none text-xs" />
                  <p v-if="item.discount_percent > 0" class="mt-0.5 text-xs text-green-600 text-center">
                    = {{ item.discount_percent.toFixed(2) }}%
                  </p>
                </td>
                <td class="px-4 py-3 text-right font-medium text-gray-700 text-xs">
                  {{ formatVnd(itemTotalWithVat(item)) }}
                  <span v-if="item.vat_rate" class="block text-xs text-blue-600 mt-0.5">+{{ item.vat_rate }}% VAT</span>
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
                <td colspan="8" class="px-5 py-8 text-center text-gray-400">Chưa có hàng hóa. Nhấn "+ Sản phẩm" hoặc "+ Dịch vụ".</td>
              </tr>
            </tbody>
            <tfoot v-if="form.items.length" class="bg-gray-50 border-t border-gray-200">
              <tr>
                <td colspan="6" class="px-4 py-2 text-right text-sm text-gray-600">Cộng hàng (chưa VAT):</td>
                <td class="px-4 py-2 text-right font-medium text-gray-700">{{ formatVnd(subtotal) }}</td>
                <td />
              </tr>
              <tr v-if="form.discount_value > 0">
                <td colspan="6" class="px-4 py-2 text-right text-sm text-gray-600">Chiết khấu:</td>
                <td class="px-4 py-2 text-right font-medium text-red-600">- {{ formatVnd(discountAmount) }}</td>
                <td />
              </tr>
              <tr v-if="totalVat > 0">
                <td colspan="6" class="px-4 py-2 text-right text-sm text-gray-500">Thuế VAT:</td>
                <td class="px-4 py-2 text-right text-sm text-blue-600">{{ formatVnd(totalVat) }}</td>
                <td />
              </tr>
              <tr>
                <td colspan="6" class="px-4 py-3 text-right font-bold text-gray-800">Tổng cộng:</td>
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
            {{ form.processing ? 'Đang lưu...' : (isEdit ? 'Cập nhật' : 'Tạo báo giá') }}
          </button>
          <Link :href="route('sales.quotations.index')"
            class="px-6 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Hủy</Link>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';
import ProductSearch from '@/Components/Shared/ProductSearch.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  quotation: Object,
  nextCode: String,
  priceLists: Array,
});

const initialCustomerDisplay = computed(() =>
  props.quotation?.customer_code && props.quotation?.customer_name
    ? `${props.quotation.customer_code} - ${props.quotation.customer_name}`
    : (props.quotation?.customer_name ?? '')
);

const selectedPriceList = ref('');
const desiredTotal = ref(null);
const roundingMode = ref('');
const discountAmountInput = ref(null);

const onProductSelect = (idx, opt) => {
  const item = form.items[idx];
  item.product_id       = opt.value;
  item._productDisplay  = opt.code ? `${opt.code} - ${opt.label}` : opt.label;
  item.name             = opt.label;
  item.unit             = opt.unit ?? opt.meta ?? '';
  item.unit_price       = opt.sell_price ?? opt.cost_price ?? 0;
  if (opt.vat_percent != null) item.vat_rate = Number(opt.vat_percent);
  item._ck_amount       = 0;
  item.discount_percent = 0;
  desiredTotal.value    = null;
  discountAmountInput.value = null;
};

const onServiceSelect = (idx, opt) => {
  if (!opt) return;
  const item = form.items[idx];
  item.service_id       = opt.value;
  item._serviceDisplay  = opt.code ? `${opt.code} - ${opt.label}` : opt.label;
  item.name             = opt.label;
  item.unit             = opt.meta ?? '';
  item.unit_price       = opt.price ?? 0;
  item._ck_amount       = 0;
  item.discount_percent = 0;
  desiredTotal.value    = null;
  discountAmountInput.value = null;
};

const applyPriceList = async () => {
  if (!selectedPriceList.value) return;
  try {
    const res = await fetch(route('catalog.price-lists.items', selectedPriceList.value));
    const items = await res.json();
    const priceMap = {};
    items.forEach(i => { priceMap[i.product_id] = i.unit_price; });
    form.items.forEach(item => {
      if (item.item_type === 'product' && item.product_id && priceMap[item.product_id] !== undefined) {
        item.unit_price = Number(priceMap[item.product_id]);
      }
    });
  } catch (e) {
    console.error('Failed to load price list items', e);
  }
};

const { formatVnd } = useCurrency();

const isEdit = !!props.quotation;
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  code:           props.quotation?.code ?? props.nextCode ?? '',
  customer_id:    props.quotation?.customer_id ?? '',
  assigned_to:    props.quotation?.assigned_to ?? null,
  valid_until:    props.quotation?.valid_until ?? '',
  discount_type:  props.quotation?.discount_type ?? 'percent',
  discount_value: props.quotation?.discount_value ?? 0,
  notes:          props.quotation?.notes ?? '',
  items:          props.quotation?.items?.map(i => ({
    ...i,
    vat_rate:        i.vat_rate ?? null,
    _ck_amount:      i.discount_amount || 0,
    _productDisplay: i.item_type === 'product' ? (i.name ?? '') : '',
    _serviceDisplay: i.item_type === 'service' ? (i.name ?? '') : '',
  })) ?? [],
});

const addRow = (type) => {
  form.items.push({
    item_type:        type,
    product_id:       type === 'product' ? null : null,
    service_id:       type === 'service' ? null : null,
    name:             '',
    unit:             '',
    quantity:         1,
    unit_price:       0,
    vat_rate:         10,
    discount_percent: 0,
    _ck_amount:       0,
    _productDisplay:  '',
    _serviceDisplay:  '',
  });
};

const removeRow = (idx) => form.items.splice(idx, 1);

// Khi nhập số tiền CK (VND) → tính lại %
const onCkAmountChange = (idx) => {
  const item      = form.items[idx];
  const lineAmt   = (item.quantity || 0) * (item.unit_price || 0);
  const ckAmt     = item._ck_amount || 0;
  if (ckAmt <= 0 || lineAmt <= 0) {
    item.discount_percent = 0;
    return;
  }
  const capped = Math.min(ckAmt, lineAmt);
  if (capped < ckAmt) item._ck_amount = capped;
  item.discount_percent = Math.round((capped / lineAmt) * 10000) / 100; // làm tròn 2 chữ số
};

// Khi SL hoặc đơn giá thay đổi → giữ nguyên số tiền CK, tính lại %
const recalcCkPercent = (idx) => {
  const item = form.items[idx];
  if (!item._ck_amount || item._ck_amount <= 0) return;
  onCkAmountChange(idx);
};

const itemLineTotal = (item) => (item.quantity || 0) * (item.unit_price || 0) - (item._ck_amount || 0);
const itemVatAmount = (item) => Math.round(itemLineTotal(item) * (item.vat_rate || 0) / 100);
const itemTotalWithVat = (item) => itemLineTotal(item) + itemVatAmount(item);

const subtotal = computed(() =>
  form.items.reduce((s, i) => s + itemLineTotal(i), 0)
);

const totalVat = computed(() =>
  form.items.reduce((s, i) => s + itemVatAmount(i), 0)
);

const discountAmount = computed(() => {
  if (form.discount_type === 'percent') return subtotal.value * (form.discount_value / 100);
  return form.discount_value;
});

const grandTotal = computed(() => subtotal.value - discountAmount.value + totalVat.value);

const calculatedDiscountPercent = computed(() => {
  if (form.discount_type === 'fixed' && discountAmountInput.value > 0) {
    // Tính % từ số tiền chiết khấu (từ input nhập tiền)
    const percent = (discountAmountInput.value / subtotal.value) * 100;
    return percent;
  } else if (form.discount_type === 'fixed') {
    // Tính % từ discount_value (khi nhập trực tiếp)
    const percent = (form.discount_value / subtotal.value) * 100;
    return percent;
  } else {
    // Nếu là phần trăm thì return trực tiếp
    return form.discount_value;
  }
});

const calculateDiscountPercent = () => {
  if (!discountAmountInput.value || discountAmountInput.value <= 0) {
    discountAmountInput.value = null;
    return;
  }

  // Cập nhật discount_type và discount_value dựa trên số tiền nhập vào
  form.discount_type = 'fixed';
  form.discount_value = discountAmountInput.value;
};

const updateDiscountType = (source) => {
  // Khi người dùng thay đổi loại chiết khấu từ dropdown
  if (source === 'manual') {
    // Reset số tiền chiết khấu input
    discountAmountInput.value = null;
  }
};

const calculateDiscountFromTotal = () => {
  if (!desiredTotal.value || desiredTotal.value <= 0) {
    form.discount_value = 0;
    form.discount_type = 'percent';
    return;
  }

  const current = subtotal.value;
  if (desiredTotal.value >= current) {
    form.discount_value = 0;
    form.discount_type = 'percent';
  } else {
    // Tính % chiết khấu để đạt được tổng tiền mong muốn
    const discountPercent = ((current - desiredTotal.value) / current) * 100;
    form.discount_type = 'percent';
    form.discount_value = Math.round(discountPercent * 100) / 100; // Làm tròn 2 chữ số thập phân
  }
};

const roundPrice = () => {
  if (!desiredTotal.value || !roundingMode.value) return;
  
  const mode = parseInt(roundingMode.value);
  const rounded = Math.round(desiredTotal.value / mode) * mode;
  desiredTotal.value = rounded;
  calculateDiscountFromTotal();
};


const submit = () => {
  const cleanItems = form.items.map(({ _ck_amount, _productDisplay, _serviceDisplay, ...rest }) => ({
    ...rest,
    vat_rate:        rest.vat_rate ?? null,
    discount_amount: Math.round(_ck_amount || 0),
  }));
  if (isEdit) {
    form.transform(d => ({ ...d, items: cleanItems })).put(route('sales.quotations.update', props.quotation.id));
  } else {
    form.transform(d => ({ ...d, items: cleanItems })).post(route('sales.quotations.store'));
  }
};
</script>
