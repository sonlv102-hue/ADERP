<template>
  <AppLayout>
    <div class="max-w-xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.personal-loans.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Tạo khoản vay cá nhân</h1>
      </div>

      <!-- JE preview -->
      <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-xs text-blue-800 space-y-1 mb-5">
        <p class="font-semibold">Bút toán sẽ sinh khi ghi sổ:</p>
        <p>Nợ {{ selectedFundAccount || '1121/1111' }} — Quỹ nhận tiền</p>
        <p>Có 3411 — Các khoản đi vay ({{ lenderDisplay || 'người cho vay' }})</p>
      </div>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <!-- Loại người cho vay -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Người cho vay <span class="text-red-500">*</span></label>
          <div class="flex gap-4 mb-3">
            <label v-for="t in lenderTypes" :key="t.value" class="flex items-center gap-2 cursor-pointer">
              <input type="radio" v-model="form.lender_type" :value="t.value" class="accent-primary-600" />
              <span class="text-sm">{{ t.label }}</span>
            </label>
          </div>
          <SearchableSelect
            v-if="form.lender_type === 'employee'"
            v-model="form.employee_id"
            :options="employeeOptions"
            placeholder="-- Chọn nhân viên --"
            :has-error="!!form.errors.employee_id"
          />
          <SearchableSelect
            v-else-if="form.lender_type === 'shareholder'"
            v-model="form.shareholder_id"
            :options="shareholderOptions"
            placeholder="-- Chọn thành viên/cổ đông --"
            :has-error="!!form.errors.shareholder_id"
          />
          <input v-else v-model="form.lender_name" type="text" placeholder="Họ tên người cho vay"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền vay <span class="text-red-500">*</span></label>
            <input v-model.number="form.amount" type="number" min="1" step="1"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500"
              :class="{ 'border-red-500': form.errors.amount }" />
            <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Lãi suất (%/năm)</label>
            <input v-model.number="form.interest_rate" type="number" min="0" max="100" step="0.01"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày vay <span class="text-red-500">*</span></label>
            <input v-model="form.loan_date" type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500"
              :class="{ 'border-red-500': form.errors.loan_date }" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ngày đến hạn</label>
            <input v-model="form.due_date" type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ nhận tiền <span class="text-red-500">*</span></label>
          <SearchableSelect
            v-model="form.fund_id"
            :options="fundOptions"
            placeholder="-- Chọn quỹ --"
            :has-error="!!form.errors.fund_id"
          />
          <p v-if="form.errors.fund_id" class="mt-1 text-xs text-red-600">{{ form.errors.fund_id }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Mục đích vay</label>
          <input v-model="form.purpose" type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="2"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : 'Tạo khoản vay' }}
          </button>
          <Link :href="route('accounting.personal-loans.index')"
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

const props = defineProps({ loan: Object, employees: Array, shareholders: Array, funds: Array });
const { formatVnd } = useCurrency();

const lenderTypes = [
  { value: 'employee', label: 'Nhân viên' },
  { value: 'shareholder', label: 'Thành viên/Cổ đông' },
  { value: 'other', label: 'Khác' },
];

const form = useForm({
  lender_type:    'employee',
  employee_id:    null,
  shareholder_id: null,
  lender_name:    '',
  amount:         null,
  interest_rate:  null,
  loan_date:      new Date().toISOString().slice(0, 10),
  due_date:       '',
  purpose:        '',
  fund_id:        null,
  notes:          '',
});

const employeeOptions = computed(() =>
  (props.employees ?? []).map(e => ({ value: e.id, code: e.code, label: e.name }))
);
const shareholderOptions = computed(() =>
  (props.shareholders ?? []).map(s => ({ value: s.id, code: s.code, label: s.name }))
);
const fundOptions = computed(() =>
  (props.funds ?? []).map(f => ({
    value: f.id,
    label: f.name,
    code: f.account_code || '',
    meta: f.type === 'bank' ? 'Ngân hàng' : 'Tiền mặt',
  }))
);

const groupedFunds = computed(() => {
  const g = { 'Tiền mặt': [], 'Ngân hàng': [] };
  for (const f of (props.funds ?? [])) {
    if (f.type === 'bank') g['Ngân hàng'].push(f);
    else g['Tiền mặt'].push(f);
  }
  return g;
});

const selectedFundAccount = computed(() => {
  const f = props.funds?.find(f => f.id === form.fund_id);
  return f?.account_code || (f?.type === 'bank' ? '1121' : '1111');
});

const lenderDisplay = computed(() => {
  if (form.lender_type === 'employee') {
    return props.employees?.find(e => e.id === form.employee_id)?.name;
  }
  if (form.lender_type === 'shareholder') {
    return props.shareholders?.find(s => s.id === form.shareholder_id)?.name;
  }
  return form.lender_name;
});

function submit() {
  form.post(route('accounting.personal-loans.store'));
}
</script>
