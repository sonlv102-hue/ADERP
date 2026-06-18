<template>
  <AppLayout>
    <div class="max-w-5xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.small-tools.receipts.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Tạo phiếu nhập kho CCDC</h1>
      </div>

      <form @submit.prevent="submit" class="space-y-5">
        <!-- Header -->
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Mã phiếu</label>
              <input :value="nextCode" disabled class="erp-input bg-gray-50 text-gray-500" />
            </div>
            <div>
              <label class="erp-label">Ngày nhập <span class="text-red-500">*</span></label>
              <input v-model="form.receipt_date" type="date" class="erp-input"
                :class="{ 'border-red-500': form.errors.receipt_date }" />
            </div>
            <div>
              <label class="erp-label">Nhà cung cấp</label>
              <SearchableSelect v-model="form.supplier_id" :options="supplierOptions" placeholder="-- Chọn NCC --" />
            </div>
            <div>
              <label class="erp-label">Kho nhập <span class="text-red-500">*</span></label>
              <SearchableSelect v-model="form.warehouse_id" :options="warehouseOptions"
                placeholder="-- Chọn kho --" :has-error="!!form.errors.warehouse_id" />
            </div>
            <div>
              <label class="erp-label">Hình thức thanh toán</label>
              <select v-model="form.payment_type" class="erp-input">
                <option value="payable">Chưa thanh toán (Có 331)</option>
                <option value="cash">Tiền mặt</option>
                <option value="bank">Ngân hàng</option>
              </select>
            </div>
            <div v-if="form.payment_type !== 'payable'">
              <label class="erp-label">Quỹ</label>
              <SearchableSelect v-model="form.fund_id" :options="fundOptions" placeholder="-- Chọn quỹ --" />
            </div>
          </div>
          <div>
            <label class="erp-label">Ghi chú</label>
            <input v-model="form.notes" type="text" class="erp-input" />
          </div>
        </div>

        <!-- Items -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-base font-semibold text-gray-800">Danh sách CCDC nhập kho</h2>
            <button type="button" @click="addItem"
              class="text-primary-600 hover:text-primary-800 text-sm font-medium">+ Thêm dòng</button>
          </div>

          <div class="divide-y divide-gray-100">
            <div v-for="(item, idx) in form.items" :key="idx" class="p-5 space-y-3">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="erp-label">Tên CCDC <span class="text-red-500">*</span></label>
                  <input v-model="item.name" type="text" class="erp-input" placeholder="VD: Bộ dụng cụ sửa máy tính..." />
                </div>
                <div>
                  <label class="erp-label">Nhóm CCDC</label>
                  <SearchableSelect v-model="item.category_id" :options="categoryOptions" placeholder="-- Nhóm --" />
                </div>
              </div>
              <div class="grid grid-cols-4 gap-3">
                <div>
                  <label class="erp-label">ĐVT</label>
                  <input v-model="item.unit" type="text" class="erp-input" placeholder="cái" />
                </div>
                <div>
                  <label class="erp-label">Số lượng</label>
                  <input v-model.number="item.quantity" type="number" min="1" class="erp-input" @input="calcLine(idx)" />
                </div>
                <div>
                  <label class="erp-label">Đơn giá (chưa VAT)</label>
                  <input v-model.number="item.unit_price" type="number" min="0" class="erp-input" @input="calcLine(idx)" />
                </div>
                <div>
                  <label class="erp-label">VAT (%)</label>
                  <input v-model.number="item.vat_rate" type="number" min="0" max="100" class="erp-input" @input="calcLine(idx)" />
                </div>
              </div>
              <div class="grid grid-cols-3 gap-3">
                <div>
                  <label class="erp-label">Ghi nhận chi phí</label>
                  <select v-model="item.recognition_method" class="erp-input" @change="calcLine(idx)">
                    <option value="immediate">Một lần (6422)</option>
                    <option value="allocation">Phân bổ nhiều kỳ</option>
                  </select>
                </div>
                <div v-if="item.recognition_method === 'allocation'">
                  <label class="erp-label">Số kỳ phân bổ</label>
                  <input v-model.number="item.allocation_periods" type="number" min="1" class="erp-input" />
                </div>
                <div>
                  <label class="erp-label">TK chi phí</label>
                  <input v-model="item.expense_account_code" type="text" class="erp-input" placeholder="6422" />
                </div>
              </div>
              <div class="flex justify-between items-center">
                <p class="text-sm text-gray-600">
                  Thành tiền: <span class="font-mono font-semibold">{{ formatVnd(item._total || 0) }}</span>
                  (VAT: <span class="font-mono text-gray-500">{{ formatVnd(item._vat || 0) }}</span>)
                </p>
                <button v-if="form.items.length > 1" type="button" @click="removeItem(idx)"
                  class="text-red-500 hover:text-red-700 text-xs">Xóa dòng</button>
              </div>
            </div>
          </div>

          <!-- Totals -->
          <div class="px-5 py-4 bg-gray-50 border-t border-gray-200">
            <div class="flex justify-end gap-8 text-sm">
              <div>Tổng tiền hàng: <span class="font-mono font-semibold">{{ formatVnd(grandCost) }}</span></div>
              <div>Tổng VAT: <span class="font-mono">{{ formatVnd(grandVat) }}</span></div>
              <div class="font-bold text-base">Tổng cộng: <span class="font-mono">{{ formatVnd(grandTotal) }}</span></div>
            </div>
          </div>
        </div>

        <!-- JE Preview -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-xs text-blue-800 space-y-1">
          <p class="font-semibold">Bút toán sau khi xác nhận:</p>
          <p>Nợ 1531 — Nhập kho CCDC: {{ formatVnd(grandCost) }}</p>
          <p v-if="grandVat > 0">Nợ 1331 — VAT đầu vào: {{ formatVnd(grandVat) }}</p>
          <p>Có {{ payableCode }} — {{ paymentLabel }}: {{ formatVnd(grandTotal) }}</p>
        </div>

        <div class="flex items-center gap-2">
          <input id="auto_confirm" type="checkbox" v-model="form.auto_confirm" class="accent-primary-600" />
          <label for="auto_confirm" class="text-sm text-gray-700">Xác nhận và tạo bút toán ngay</label>
        </div>

        <div class="flex gap-3">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : 'Tạo phiếu nhập' }}
          </button>
          <Link :href="route('accounting.small-tools.receipts.index')"
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
import SearchableSelect from '@/Components/Shared/SearchableSelect.vue';
import { useCurrency } from '@/composables/useCurrency';

const { formatVnd } = useCurrency();

const props = defineProps({
  nextCode: String, warehouses: Array, suppliers: Array, funds: Array, categories: Array,
});

const form = useForm({
  receipt_date:  new Date().toISOString().slice(0, 10),
  supplier_id:   null,
  warehouse_id:  null,
  payment_type:  'payable',
  fund_id:       null,
  notes:         '',
  auto_confirm:  false,
  items: [newItem()],
});

function newItem() {
  return {
    name: '', category_id: null, unit: 'cái', quantity: 1,
    unit_price: 0, vat_rate: 0, recognition_method: 'immediate',
    allocation_periods: null, expense_account_code: '6422',
    _total: 0, _vat: 0,
  };
}

function addItem()         { form.items.push(newItem()); }
function removeItem(idx)   { form.items.splice(idx, 1); }

function calcLine(idx) {
  const item = form.items[idx];
  const cost = (item.quantity || 0) * (item.unit_price || 0);
  item._vat   = Math.round(cost * (item.vat_rate || 0) / 100);
  item._total = cost + item._vat;
}

const grandCost  = computed(() => form.items.reduce((s, i) => s + (i.quantity || 0) * (i.unit_price || 0), 0));
const grandVat   = computed(() => form.items.reduce((s, i) => s + (i._vat || 0), 0));
const grandTotal = computed(() => grandCost.value + grandVat.value);

const supplierOptions  = computed(() => (props.suppliers  ?? []).map(s => ({ value: s.id, code: s.code, label: s.name })));
const warehouseOptions = computed(() => (props.warehouses ?? []).map(w => ({ value: w.id, label: w.name })));
const fundOptions      = computed(() => (props.funds      ?? []).map(f => ({ value: f.id, label: f.name, meta: f.type === 'bank' ? 'NH' : 'TM' })));
const categoryOptions  = computed(() => (props.categories ?? []).map(c => ({ value: c.id, label: c.name })));

const payableCode  = computed(() => form.payment_type === 'payable' ? '3311' : (form.payment_type === 'bank' ? '1121' : '1111'));
const paymentLabel = computed(() => ({ payable: 'Công nợ NCC', cash: 'Tiền mặt', bank: 'Ngân hàng' }[form.payment_type]));

function submit() {
  form.post(route('accounting.small-tools.receipts.store'));
}
</script>
