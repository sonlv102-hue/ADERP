<template>
  <AppLayout>
    <div class="max-w-3xl mx-auto space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('accounting.prepaid-expenses.index')" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ expense.code }}</h1>
            <p class="text-sm text-gray-500">{{ expense.description }}</p>
          </div>
        </div>
        <StatusBadge :color="expense.status_color">{{ expense.status_label }}</StatusBadge>
      </div>

      <!-- Info -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <p class="text-gray-500">TK chi phí trả trước</p>
            <p class="font-mono font-semibold text-gray-900 mt-1">{{ expense.account_code }}</p>
          </div>
          <div>
            <p class="text-gray-500">TK phân bổ vào</p>
            <p class="font-mono font-semibold text-gray-900 mt-1">{{ expense.expense_account }}</p>
          </div>
          <div>
            <p class="text-gray-500">Nhà cung cấp</p>
            <p class="font-medium text-gray-900 mt-1">{{ expense.supplier_name ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-500">Người tạo</p>
            <p class="font-medium text-gray-900 mt-1">{{ expense.creator ?? '—' }}</p>
          </div>
          <div>
            <p class="text-gray-500">Kỳ phân bổ</p>
            <p class="font-medium text-gray-900 mt-1">{{ expense.start_date }} → {{ expense.end_date }} ({{ expense.months }} tháng)</p>
          </div>
          <div>
            <p class="text-gray-500">Mỗi tháng</p>
            <p class="font-semibold text-blue-700 mt-1">{{ fmt(expense.monthly_amount) }}</p>
          </div>
        </div>

        <!-- Progress bar -->
        <div class="mt-5 pt-4 border-t border-gray-100">
          <div class="flex items-center justify-between text-sm mb-2">
            <span class="text-gray-600">Tiến độ phân bổ</span>
            <span class="font-semibold text-gray-800">{{ fmt(expense.amortized_amount) }} / {{ fmt(expense.total_amount) }}</span>
          </div>
          <div class="h-3 bg-gray-200 rounded-full overflow-hidden">
            <div class="h-full bg-blue-500 rounded-full transition-all"
              :style="{ width: progressPct + '%' }" />
          </div>
          <div class="flex justify-between text-xs text-gray-500 mt-1">
            <span>{{ expense.allocations.length }} / {{ expense.months }} kỳ</span>
            <span class="font-medium" :class="expense.remaining_amount > 0 ? 'text-blue-600' : 'text-green-600'">
              Còn lại: {{ fmt(expense.remaining_amount) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Allocations table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
          <h3 class="font-semibold text-gray-700">Lịch sử phân bổ</h3>
          <form v-if="expense.status === 'active'" @submit.prevent="submitAmortize" class="flex items-center gap-2 flex-wrap">
            <input v-model="amortizeForm.period" type="month" required
              class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
            <button type="submit" :disabled="amortizeForm.processing"
              class="bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 text-sm rounded-lg disabled:opacity-50">
              Phân bổ
            </button>
          </form>
        </div>

        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Kỳ</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Phiếu kế toán</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="a in expense.allocations" :key="a.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-medium text-gray-800">{{ a.period }}</td>
              <td class="px-5 py-3 text-right font-semibold text-blue-700">{{ fmt(a.amount) }}</td>
              <td class="px-5 py-3">
                <Link v-if="a.journal_entry_id"
                  :href="route('accounting.journal-entries.show', a.journal_entry_id)"
                  class="text-primary-600 hover:text-primary-800 font-mono text-xs">
                  {{ a.journal_entry_code }}
                </Link>
                <span v-else class="text-gray-400 text-xs">—</span>
              </td>
            </tr>
            <tr v-if="!expense.allocations.length">
              <td colspan="3" class="px-5 py-8 text-center text-gray-400">Chưa có kỳ phân bổ nào</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ expense: Object, currentPeriod: String });
const { formatVnd: fmt } = useCurrency();

const progressPct = computed(() =>
  props.expense.total_amount > 0
    ? Math.min(100, (props.expense.amortized_amount / props.expense.total_amount) * 100)
    : 0
);

const amortizeForm = useForm({ period: props.currentPeriod });

function submitAmortize() {
  amortizeForm.post(route('accounting.prepaid-expenses.amortize', props.expense.id), {
    onSuccess: () => amortizeForm.reset('period'),
  });
}
</script>
