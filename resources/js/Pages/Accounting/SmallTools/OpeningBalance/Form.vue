<template>
  <AppLayout>
    <div class="max-w-2xl">
      <div class="flex items-center gap-3 mb-6">
        <Link :href="route('accounting.small-tools.index')" class="text-gray-500 hover:text-gray-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Số dư đầu kỳ CCDC</h1>
      </div>

      <p class="text-sm text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-5">
        Dùng để chuyển CCDC đang phân bổ dở dang từ hệ thống cũ. Chỉ ghi nhận
        <strong>giá trị còn lại</strong> vào TK chờ phân bổ, đối ứng TK 4111.
      </p>

      <form @submit.prevent="submit" class="space-y-5">
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Thông tin CCDC</h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Mã CCDC</label>
              <input :value="nextCode" disabled class="erp-input bg-gray-50 text-gray-500" />
            </div>
            <div>
              <label class="erp-label">Đơn vị tính</label>
              <input v-model="form.unit" type="text" class="erp-input" placeholder="cái" />
            </div>
          </div>

          <div>
            <label class="erp-label">Tên CCDC <span class="text-red-500">*</span></label>
            <input v-model="form.name" type="text" class="erp-input" :class="{ 'border-red-500': form.errors.name }" />
            <p v-if="form.errors.name" class="erp-error">{{ form.errors.name }}</p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Nhóm CCDC</label>
              <SearchableSelect v-model="form.category_id" :options="categoryOptions" placeholder="-- Chọn nhóm --" />
            </div>
            <div>
              <label class="erp-label">Số lượng <span class="text-red-500">*</span></label>
              <input v-model.number="form.quantity" type="number" min="1" class="erp-input" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Bộ phận sử dụng</label>
              <input v-model="form.department" type="text" class="erp-input" />
            </div>
            <div>
              <label class="erp-label">Người sử dụng</label>
              <SearchableSelect v-model="form.responsible_employee_id" :options="employeeOptions" placeholder="-- Chọn nhân viên --" />
            </div>
          </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Giá trị &amp; lịch phân bổ</h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div>
              <label class="erp-label">Nguyên giá (VND) <span class="text-red-500">*</span></label>
              <input v-model.number="form.original_cost" type="number" min="0" class="erp-input"
                :class="{ 'border-red-500': form.errors.original_cost }" />
            </div>
            <div>
              <label class="erp-label">Tổng số kỳ phân bổ <span class="text-red-500">*</span></label>
              <input v-model.number="form.allocation_periods" type="number" min="1" class="erp-input" />
            </div>
            <div>
              <label class="erp-label">Số kỳ đã phân bổ (hệ cũ) <span class="text-red-500">*</span></label>
              <input v-model.number="form.periods_elapsed" type="number" min="0" class="erp-input" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">Giá trị còn lại đầu kỳ (VND) <span class="text-red-500">*</span></label>
              <input v-model.number="form.remaining_amount" type="number" min="0" class="erp-input"
                :class="{ 'border-red-500': form.errors.remaining_amount }" />
              <p v-if="form.errors.remaining_amount" class="erp-error">{{ form.errors.remaining_amount }}</p>
            </div>
            <div>
              <label class="erp-label">Kỳ chuyển đổi <span class="text-red-500">*</span></label>
              <input v-model="form.opening_balance_period" type="month" class="erp-input" />
            </div>
          </div>

          <div v-if="remainingMonths > 0" class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-800">
            Còn {{ remainingMonths }} kỳ. Phân bổ mỗi kỳ dự kiến: <strong>{{ fmt(monthlyPreview) }} ₫</strong>
          </div>
          <p v-else class="text-red-600 text-xs">Số kỳ đã phân bổ phải nhỏ hơn tổng số kỳ.</p>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
          <h2 class="text-base font-semibold text-gray-800">Tài khoản kế toán</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="erp-label">TK chờ phân bổ</label>
              <input v-model="form.pending_account_code" type="text" class="erp-input" placeholder="2422" />
            </div>
            <div>
              <label class="erp-label">TK chi phí</label>
              <input v-model="form.expense_account_code" type="text" class="erp-input" placeholder="6422" />
            </div>
          </div>
        </div>

        <div>
          <label class="erp-label">Ghi chú</label>
          <textarea v-model="form.notes" rows="2" class="erp-input" />
        </div>

        <div class="flex gap-3 pt-2">
          <button type="submit" :disabled="form.processing || remainingMonths <= 0"
            class="bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white px-6 py-2 rounded-lg font-medium text-sm">
            {{ form.processing ? 'Đang lưu...' : 'Ghi nhận số dư đầu kỳ' }}
          </button>
          <Link :href="route('accounting.small-tools.index')"
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

const props = defineProps({
  nextCode:   String,
  categories: { type: Array, default: () => [] },
  employees:  { type: Array, default: () => [] },
});

const { formatVnd: fmt } = useCurrency();

const categoryOptions = computed(() => props.categories.map(c => ({ value: c.id, label: c.name })));
const employeeOptions = computed(() => props.employees.map(e => ({ value: e.id, label: `${e.code} - ${e.name}` })));

const currentPeriod = new Date().toISOString().slice(0, 7);
const form = useForm({
  name: '', category_id: null, unit: 'cái', quantity: 1,
  department: '', responsible_employee_id: null,
  original_cost: 0, allocation_periods: 12, periods_elapsed: 0, remaining_amount: 0,
  opening_balance_period: currentPeriod,
  pending_account_code: '2422', expense_account_code: '6422',
  notes: '',
});

const remainingMonths = computed(() => form.allocation_periods - form.periods_elapsed);
const monthlyPreview  = computed(() => remainingMonths.value > 0 ? Math.floor(form.remaining_amount / remainingMonths.value) : 0);

function submit() {
  form.post(route('accounting.small-tools.opening-balance.store'));
}
</script>
