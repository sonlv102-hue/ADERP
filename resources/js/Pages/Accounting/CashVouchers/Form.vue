<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.cash-vouchers.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ voucher ? 'Sửa phiếu' : (form.type === 'receipt' ? 'Tạo phiếu thu' : 'Tạo phiếu chi') }}
        </h1>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Mã phiếu</label>
            <input :value="form.code" readonly
              class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-500" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Loại phiếu</label>
            <div class="flex gap-3 mt-1">
              <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" v-model="form.type" value="receipt" :disabled="!!voucher"
                  class="text-green-600" />
                <span class="text-sm font-medium text-green-700">Phiếu thu</span>
              </label>
              <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" v-model="form.type" value="payment" :disabled="!!voucher"
                  class="text-red-600" />
                <span class="text-sm font-medium text-red-700">Phiếu chi</span>
              </label>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ <span class="text-red-500">*</span></label>
            <select v-model="form.fund_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.fund_id }">
              <option value="">-- Chọn quỹ --</option>
              <option v-for="f in funds" :key="f.id" :value="f.id">
                {{ f.name }} ({{ f.type === 'cash' ? 'Tiền mặt' : 'Ngân hàng' }})
              </option>
            </select>
            <p v-if="form.errors.fund_id" class="mt-1 text-xs text-red-600">{{ form.errors.fund_id }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày <span class="text-red-500">*</span></label>
            <input v-model="form.voucher_date" type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.voucher_date }" />
            <p v-if="form.errors.voucher_date" class="mt-1 text-xs text-red-600">{{ form.errors.voucher_date }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền <span class="text-red-500">*</span></label>
            <input v-model.number="form.amount" type="number" min="1" step="any"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.amount }" />
            <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
          </div>

          <!-- Loại đối tác -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Loại đối tác</label>
            <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1">
              <label v-for="opt in partnerTypeOptions" :key="opt.value" class="flex items-center gap-1.5 cursor-pointer">
                <input type="radio" v-model="form.partner_type" :value="opt.value" class="text-primary-600"
                  @change="onPartnerTypeChange" />
                <span class="text-sm">{{ opt.label }}</span>
              </label>
            </div>
          </div>

          <!-- Tên đối tác (free text) -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tên đối tác</label>
            <input v-model="form.counterparty" type="text" placeholder="Tên người/tổ chức..."
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none" />
          </div>

          <!-- Nhà cung cấp (combobox) -->
          <div v-if="form.partner_type === 'supplier'" class="sm:col-span-2 relative">
            <label class="block text-sm font-medium text-gray-700 mb-1">Nhà cung cấp</label>
            <div class="relative">
              <input
                v-model="supplierInput"
                type="text"
                placeholder="Tìm nhà cung cấp..."
                autocomplete="off"
                class="w-full px-3 py-2 pr-8 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                @focus="showSupplierDropdown = true"
                @blur="closeSupplierDropdown"
              />
              <button v-if="form.supplier_id" type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-lg leading-none"
                @mousedown.prevent="clearSupplier" title="Bỏ chọn">×</button>
            </div>
            <ul v-if="showSupplierDropdown && filteredSuppliers.length"
              class="absolute z-20 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-52 overflow-y-auto mt-1">
              <li v-for="s in filteredSuppliers" :key="s.id"
                @mousedown.prevent="selectSupplier(s)"
                class="px-3 py-2 cursor-pointer hover:bg-primary-50 flex items-center gap-2 text-sm">
                <span class="text-gray-400 font-mono text-xs w-20 shrink-0">{{ s.code }}</span>
                <span class="truncate">{{ s.name }}</span>
              </li>
            </ul>
            <p v-if="form.supplier_id" class="mt-1 text-xs text-primary-600">
              Đã liên kết — bút toán Cr {{ supplierAccountHint }}
            </p>
          </div>

          <!-- Khách hàng (combobox) -->
          <div v-if="form.partner_type === 'customer'" class="sm:col-span-2 relative">
            <label class="block text-sm font-medium text-gray-700 mb-1">Khách hàng</label>
            <div class="relative">
              <input
                v-model="customerInput"
                type="text"
                placeholder="Tìm khách hàng..."
                autocomplete="off"
                class="w-full px-3 py-2 pr-8 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
                @focus="showCustomerDropdown = true"
                @blur="closeCustomerDropdown"
              />
              <button v-if="form.customer_id" type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-lg leading-none"
                @mousedown.prevent="clearCustomer" title="Bỏ chọn">×</button>
            </div>
            <ul v-if="showCustomerDropdown && filteredCustomers.length"
              class="absolute z-20 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-52 overflow-y-auto mt-1">
              <li v-for="c in filteredCustomers" :key="c.id"
                @mousedown.prevent="selectCustomer(c)"
                class="px-3 py-2 cursor-pointer hover:bg-primary-50 flex items-center gap-2 text-sm">
                <span class="text-gray-400 font-mono text-xs w-20 shrink-0">{{ c.code }}</span>
                <span class="truncate">{{ c.name }}</span>
              </li>
            </ul>
            <p v-if="form.customer_id" class="mt-1 text-xs text-primary-600">
              Đã liên kết — bút toán Cr 131x
            </p>
          </div>

          <!-- Nhân viên (select) -->
          <div v-if="form.partner_type === 'employee'" class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Nhân viên</label>
            <select v-model="form.employee_id" @change="onEmployeeChange"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none">
              <option :value="null">-- Chọn nhân viên --</option>
              <option v-for="e in employees" :key="e.id" :value="e.id">
                {{ e.code }} — {{ e.name }}
              </option>
            </select>
            <p v-if="form.employee_id" class="mt-1 text-xs text-primary-600">
              Đã liên kết — bút toán TK 141 (Tạm ứng)
            </p>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải <span class="text-red-500">*</span></label>
            <input v-model="form.description" type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 outline-none"
              :class="{ 'border-red-500': form.errors.description }" />
            <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <Link :href="route('accounting.cash-vouchers.index')"
            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
            Hủy
          </Link>
          <button type="submit" :disabled="form.processing"
            class="px-5 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-lg disabled:opacity-50">
            {{ voucher ? 'Cập nhật' : 'Lưu phiếu' }}
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';

const props = defineProps({
  voucher:     Object,
  funds:       Array,
  nextCode:    String,
  defaultType: String,
  suppliers:   Array,
  customers:   Array,
  employees:   Array,
});

const partnerTypeOptions = [
  { value: null,       label: 'Không có' },
  { value: 'supplier', label: 'Nhà cung cấp' },
  { value: 'customer', label: 'Khách hàng' },
  { value: 'employee', label: 'Nhân viên' },
];

const form = useForm({
  code:         props.voucher?.code         ?? props.nextCode,
  type:         props.voucher?.type         ?? props.defaultType ?? 'receipt',
  fund_id:      props.voucher?.fund_id      ?? '',
  amount:       props.voucher?.amount       ?? '',
  voucher_date: props.voucher?.voucher_date ?? new Date().toISOString().slice(0, 10),
  counterparty: props.voucher?.counterparty ?? '',
  partner_type: props.voucher?.partner_type ?? null,
  supplier_id:  props.voucher?.supplier_id  ?? null,
  customer_id:  props.voucher?.customer_id  ?? null,
  employee_id:  props.voucher?.employee_id  ?? null,
  description:  props.voucher?.description  ?? '',
});

// ── Supplier combobox ──────────────────────────────────────────────────────────
const showSupplierDropdown = ref(false);
const supplierInput = ref(props.suppliers?.find(s => s.id === props.voucher?.supplier_id)?.name ?? '');
const supplierAccountHint = ref('3311');

const filteredSuppliers = computed(() => {
  if (!props.suppliers?.length) return [];
  const q = supplierInput.value.toLowerCase().trim();
  if (!q) return props.suppliers.slice(0, 8);
  return props.suppliers.filter(s =>
    s.name.toLowerCase().includes(q) || s.code.toLowerCase().includes(q)
  ).slice(0, 10);
});

watch(supplierInput, (val) => {
  if (!form.supplier_id) return;
  const matched = props.suppliers?.find(s => s.id === form.supplier_id);
  if (matched && val !== matched.name) clearSupplier();
});

function selectSupplier(s) {
  form.supplier_id = s.id;
  form.counterparty = s.name;
  supplierInput.value = s.name;
  showSupplierDropdown.value = false;
}

function clearSupplier() {
  form.supplier_id = null;
  supplierInput.value = '';
}

function closeSupplierDropdown() {
  setTimeout(() => { showSupplierDropdown.value = false; }, 150);
}

// ── Customer combobox ─────────────────────────────────────────────────────────
const showCustomerDropdown = ref(false);
const customerInput = ref(props.customers?.find(c => c.id === props.voucher?.customer_id)?.name ?? '');

const filteredCustomers = computed(() => {
  if (!props.customers?.length) return [];
  const q = customerInput.value.toLowerCase().trim();
  if (!q) return props.customers.slice(0, 8);
  return props.customers.filter(c =>
    c.name.toLowerCase().includes(q) || c.code.toLowerCase().includes(q)
  ).slice(0, 10);
});

watch(customerInput, (val) => {
  if (!form.customer_id) return;
  const matched = props.customers?.find(c => c.id === form.customer_id);
  if (matched && val !== matched.name) clearCustomer();
});

function selectCustomer(c) {
  form.customer_id = c.id;
  form.counterparty = c.name;
  customerInput.value = c.name;
  showCustomerDropdown.value = false;
}

function clearCustomer() {
  form.customer_id = null;
  customerInput.value = '';
}

function closeCustomerDropdown() {
  setTimeout(() => { showCustomerDropdown.value = false; }, 150);
}

// ── Employee ──────────────────────────────────────────────────────────────────
function onEmployeeChange() {
  const emp = props.employees?.find(e => e.id === form.employee_id);
  if (emp) form.counterparty = emp.name;
}

// ── Partner type change: clear previous partner fields ────────────────────────
function onPartnerTypeChange() {
  form.supplier_id = null;
  form.customer_id = null;
  form.employee_id = null;
  supplierInput.value = '';
  customerInput.value = '';
}

// ─────────────────────────────────────────────────────────────────────────────

function submit() {
  if (props.voucher) {
    form.put(route('accounting.cash-vouchers.update', props.voucher.id));
  } else {
    form.post(route('accounting.cash-vouchers.store'));
  }
}
</script>
