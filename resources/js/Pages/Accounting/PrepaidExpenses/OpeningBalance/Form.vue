<template>
  <AppLayout>
    <div class="max-w-2xl mx-auto space-y-6">
      <div class="flex items-center gap-3">
        <Link :href="route('accounting.prepaid-expenses.index')" class="text-gray-400 hover:text-gray-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
        </Link>
        <h1 class="text-2xl font-bold text-gray-900">Số dư đầu kỳ — Chi phí trả trước</h1>
      </div>

      <p class="text-sm text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3">
        Dùng để chuyển số dư chi phí trả trước còn đang phân bổ từ hệ thống cũ. Chỉ ghi nhận
        <strong>giá trị còn lại</strong> (không phải tổng giá trị ban đầu), đối ứng TK 4111.
      </p>

      <form @submit.prevent="submit" class="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải *</label>
          <input v-model="form.description" required
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          <p v-if="form.errors.description" class="text-red-600 text-xs mt-1">{{ form.errors.description }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nhà cung cấp</label>
            <RemoteSearchSelect
              v-model="form.supplier_id"
              :display-text="form.supplier_name"
              :search-url="route('search.suppliers')"
              placeholder="Tìm nhà cung cấp..."
              @change="(opt) => form.supplier_name = opt ? opt.label : ''"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">TK theo dõi *</label>
            <select v-model="form.account_code" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
              <option v-for="opt in accountOptions" :key="opt.code" :value="opt.code">{{ opt.label }}</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">TK phân bổ vào chi phí *</label>
          <select v-model="form.expense_account" required
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option v-for="opt in expenseOptions" :key="opt.code" :value="opt.code">{{ opt.label }}</option>
          </select>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tổng giá trị ban đầu (VND) *</label>
            <input v-model.number="form.total_amount" type="number" min="1" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tổng số kỳ phân bổ *</label>
            <input v-model.number="form.months" type="number" min="1" max="120" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Số kỳ đã phân bổ (hệ cũ) *</label>
            <input v-model.number="form.periods_elapsed" type="number" min="0" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Giá trị còn lại đầu kỳ (VND) *</label>
            <div class="flex gap-2">
              <input v-model.number="remainingAbs" type="number" min="0" required
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            </div>
            <p v-if="form.errors.remaining_amount" class="text-red-600 text-xs mt-1">{{ form.errors.remaining_amount }}</p>
          </div>
          <label class="flex items-center gap-2 text-sm text-gray-700 pb-2">
            <input type="checkbox" v-model="isNegative" class="rounded border-gray-300" />
            Số dư âm (đã phân bổ vượt ở hệ cũ — sẽ tạo bút toán đảo chiều)
          </label>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Kỳ chuyển đổi (số dư tính đến hết tháng trước) *</label>
          <input v-model="form.opening_balance_period" type="month" required
            class="w-full sm:w-56 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <!-- Preview -->
        <div v-if="remainingMonths > 0"
          class="rounded-lg px-4 py-3 text-sm"
          :class="signedRemaining < 0 ? 'bg-orange-50 border border-orange-200 text-orange-800' : 'bg-blue-50 border border-blue-200 text-blue-800'">
          Giá trị còn lại: <strong>{{ fmt(signedRemaining) }} ₫</strong> — còn {{ remainingMonths }} kỳ.
          Phân bổ mỗi kỳ dự kiến: <strong>{{ fmt(monthlyPreview) }} ₫</strong>
        </div>
        <p v-else class="text-red-600 text-xs">Số kỳ đã phân bổ phải nhỏ hơn tổng số kỳ.</p>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ghi chú</label>
          <textarea v-model="form.notes" rows="2"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
          <Link :href="route('accounting.prepaid-expenses.index')"
            class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">Hủy</Link>
          <button type="submit" :disabled="form.processing || remainingMonths <= 0"
            class="px-5 py-2 text-sm bg-primary-600 text-white rounded-lg hover:bg-primary-700 disabled:opacity-50">
            Ghi nhận số dư đầu kỳ
          </button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';
import RemoteSearchSelect from '@/Components/Shared/RemoteSearchSelect.vue';

const props = defineProps({
  suppliers:      { type: Array, default: () => [] },
  accountOptions: Array,
  expenseOptions: Array,
});

const { formatVnd: fmt } = useCurrency();

const currentPeriod = new Date().toISOString().slice(0, 7);
const remainingAbs = ref(0);
const isNegative    = ref(false);

const form = useForm({
  description:            '',
  supplier_id:            null,
  supplier_name:          '',
  account_code:           '242',
  expense_account:        '6422',
  total_amount:           0,
  months:                 12,
  periods_elapsed:        0,
  remaining_amount:       0,
  opening_balance_period: currentPeriod,
  notes:                  '',
});

const signedRemaining = computed(() => isNegative.value ? -Math.abs(remainingAbs.value) : Math.abs(remainingAbs.value));
watch(signedRemaining, (v) => { form.remaining_amount = v; }, { immediate: true });

const remainingMonths = computed(() => form.months - form.periods_elapsed);
const monthlyPreview  = computed(() => remainingMonths.value > 0 ? Math.round(signedRemaining.value / remainingMonths.value) : 0);

function submit() {
  form.post(route('accounting.prepaid-expenses.opening-balance.store'));
}
</script>
