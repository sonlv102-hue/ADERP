<template>
  <AppLayout>
    <div class="max-w-3xl space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
          <Link :href="route('accounting.personal-loans.index')" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 class="text-xl font-bold text-gray-900">{{ loan.loan_no }}</h1>
            <p class="text-sm text-gray-500">Vay cá nhân · {{ loan.loan_date_f }}</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span :class="statusClass(loan.status_color)" class="inline-flex px-3 py-1 rounded-full text-xs font-semibold">
            {{ loan.status_label }}
          </span>
          <button v-if="loan.status === 'draft'" @click="postLoan"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">
            Ghi sổ nhận tiền
          </button>
          <button v-if="loan.status === 'draft'" @click="cancelLoan"
            class="border border-red-300 text-red-600 hover:bg-red-50 px-4 py-2 rounded-lg text-sm">
            Hủy khoản vay
          </button>
        </div>
      </div>

      <!-- Flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm">
        {{ $page.props.flash.error }}
      </div>

      <!-- Detail -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
          <div>
            <p class="text-xs text-gray-500">Người cho vay</p>
            <p class="font-medium text-gray-900">{{ loan.lender_name }}</p>
            <p class="text-xs text-gray-400 capitalize">{{ lenderTypeLabel }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Số tiền vay</p>
            <p class="font-bold text-gray-900 text-base">{{ formatVnd(loan.amount) }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Còn lại</p>
            <p :class="loan.remaining > 0 ? 'text-orange-600 font-bold' : 'text-green-600 font-bold'" class="text-base">
              {{ formatVnd(loan.remaining) }}
            </p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Ngày vay</p>
            <p class="font-medium">{{ loan.loan_date_f }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Hạn trả</p>
            <p class="font-medium">{{ loan.due_date_f || '—' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Lãi suất</p>
            <p class="font-medium">{{ loan.interest_rate != null ? loan.interest_rate + '%/năm' : '—' }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Quỹ nhận tiền</p>
            <p class="font-medium">{{ loan.fund?.name || '—' }}</p>
            <p v-if="loan.fund?.account_code" class="text-xs text-gray-400 font-mono">TK {{ loan.fund.account_code }}</p>
          </div>
          <div class="col-span-2">
            <p class="text-xs text-gray-500">Mục đích</p>
            <p class="font-medium text-gray-700">{{ loan.purpose || '—' }}</p>
          </div>
        </div>

        <!-- JE link -->
        <div v-if="loan.journal_entry" class="pt-3 border-t border-gray-100 text-sm text-gray-600">
          Bút toán ghi sổ: <span class="font-mono font-semibold text-gray-800">{{ loan.journal_entry.code }}</span>
        </div>
      </div>

      <!-- Repayments -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-4 py-3 bg-gray-50 border-b border-gray-200">
          <h3 class="text-sm font-semibold text-gray-700">Lịch sử trả nợ</h3>
          <button v-if="['active','partially_repaid'].includes(loan.status)" @click="showRepayModal = true"
            class="text-sm text-primary-600 hover:text-primary-700 font-medium">+ Thêm đợt trả</button>
        </div>
        <div v-if="loan.repayments.length === 0" class="px-4 py-6 text-center text-gray-400 text-sm">
          Chưa có đợt trả nào.
        </div>
        <table v-else class="w-full text-sm">
          <thead>
            <tr class="border-b border-gray-100">
              <th class="text-left px-4 py-2 text-xs font-medium text-gray-500">Ngày trả</th>
              <th class="text-right px-4 py-2 text-xs font-medium text-gray-500">Số tiền</th>
              <th class="text-left px-4 py-2 text-xs font-medium text-gray-500">Quỹ</th>
              <th class="text-left px-4 py-2 text-xs font-medium text-gray-500">Diễn giải</th>
              <th class="text-left px-4 py-2 text-xs font-medium text-gray-500">Bút toán</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="r in loan.repayments" :key="r.id">
              <td class="px-4 py-2">{{ r.repayment_date }}</td>
              <td class="px-4 py-2 text-right font-medium">{{ formatVnd(r.amount) }}</td>
              <td class="px-4 py-2 text-gray-600">{{ r.fund }}</td>
              <td class="px-4 py-2 text-gray-600">{{ r.description || '—' }}</td>
              <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ r.je_code || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Repay modal -->
    <div v-if="showRepayModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4 shadow-xl space-y-4">
        <h3 class="font-semibold text-gray-900">Ghi nhận đợt trả nợ</h3>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Ngày trả <span class="text-red-500">*</span></label>
          <input v-model="repayForm.repayment_date" type="date" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Số tiền (còn lại: {{ formatVnd(loan.remaining) }}) <span class="text-red-500">*</span></label>
          <input v-model.number="repayForm.amount" type="number" min="1" :max="loan.remaining"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ thanh toán <span class="text-red-500">*</span></label>
          <select v-model="repayForm.fund_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
            <option :value="null">-- Chọn quỹ --</option>
            <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải</label>
          <input v-model="repayForm.description" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" />
        </div>
        <div class="flex gap-3 justify-end pt-2">
          <button @click="showRepayModal = false" class="px-4 py-2 border border-gray-300 rounded-lg text-sm">Hủy</button>
          <button @click="submitRepay" :disabled="!repayForm.fund_id || !repayForm.amount"
            class="px-4 py-2 bg-primary-600 hover:bg-primary-700 disabled:opacity-60 text-white rounded-lg text-sm font-semibold">
            Ghi nhận
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ loan: Object, funds: Array });
const { formatVnd } = useCurrency();

const showRepayModal = ref(false);
const repayForm = ref({
  repayment_date: new Date().toISOString().slice(0, 10),
  amount: null,
  fund_id: null,
  description: '',
});

const lenderTypeLabel = computed(() => ({
  employee: 'Nhân viên', shareholder: 'Thành viên/Cổ đông', other: 'Khác'
})[props.loan.lender_type] ?? '');

function statusClass(color) {
  const map = { gray: 'bg-gray-100 text-gray-600', blue: 'bg-blue-100 text-blue-700',
    yellow: 'bg-yellow-100 text-yellow-700', green: 'bg-green-100 text-green-700', red: 'bg-red-100 text-red-700' };
  return map[color] ?? 'bg-gray-100 text-gray-600';
}
function postLoan() {
  router.post(route('accounting.personal-loans.post', props.loan.id));
}
function cancelLoan() {
  if (confirm('Hủy khoản vay này?')) router.post(route('accounting.personal-loans.cancel', props.loan.id));
}
function submitRepay() {
  router.post(route('accounting.personal-loans.repay', props.loan.id), repayForm.value, {
    onSuccess: () => { showRepayModal.value = false; repayForm.value = { repayment_date: new Date().toISOString().slice(0, 10), amount: null, fund_id: null, description: '' }; },
  });
}
</script>
