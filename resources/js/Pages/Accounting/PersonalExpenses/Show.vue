<template>
  <AppLayout>
    <div class="max-w-3xl space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
          <Link :href="route('accounting.personal-expenses.index')" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <h1 class="text-xl font-bold text-gray-900">{{ report.report_no }}</h1>
            <p class="text-sm text-gray-500">Phiếu chi hộ · {{ report.expense_date_f }}</p>
          </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <span :class="statusClass(report.status_color)" class="inline-flex px-3 py-1 rounded-full text-xs font-semibold">
            {{ report.status_label }}
          </span>
          <button v-if="report.status === 'draft'" @click="postReport"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">
            Ghi sổ
          </button>
          <button v-if="report.status === 'posted'" @click="showReimburseModal = true"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">
            Hoàn tiền
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

      <!-- Header info -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
          <div>
            <p class="text-xs text-gray-500">Người chi hộ</p>
            <p class="font-medium text-gray-900">{{ report.person_name }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Ngày chi</p>
            <p class="font-medium">{{ report.expense_date_f }}</p>
          </div>
          <div>
            <p class="text-xs text-gray-500">Tổng tiền</p>
            <p class="font-bold text-gray-900 text-base">{{ formatVnd(report.total_amount) }}</p>
          </div>
          <div class="col-span-full">
            <p class="text-xs text-gray-500">Nội dung</p>
            <p class="font-medium text-gray-700">{{ report.description }}</p>
          </div>
        </div>

        <div v-if="report.journal_entry" class="pt-3 border-t border-gray-100 text-sm text-gray-600">
          Bút toán ghi nhận: <span class="font-mono font-semibold text-gray-800">{{ report.journal_entry.code }}</span>
        </div>
        <div v-if="report.reimburse_je" class="text-sm text-gray-600">
          Bút toán hoàn tiền: <span class="font-mono font-semibold text-gray-800">{{ report.reimburse_je.code }}</span>
          <span class="ml-2 text-gray-400">({{ report.reimbursed_at }})</span>
        </div>
      </div>

      <!-- Lines -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
          <h3 class="text-sm font-semibold text-gray-700">Chi tiết khoản chi</h3>
        </div>
        <table class="min-w-full text-sm">
          <thead class="border-b border-gray-100">
            <tr>
              <th class="text-left px-4 py-2 text-xs font-medium text-gray-500">TK</th>
              <th class="text-left px-4 py-2 text-xs font-medium text-gray-500">Diễn giải</th>
              <th class="text-right px-4 py-2 text-xs font-medium text-gray-500">Tổng tiền</th>
              <th class="text-right px-4 py-2 text-xs font-medium text-gray-500">VAT%</th>
              <th class="text-right px-4 py-2 text-xs font-medium text-gray-500">Tiền VAT</th>
              <th class="text-right px-4 py-2 text-xs font-medium text-gray-500">Chưa VAT</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-for="l in report.lines" :key="l.id">
              <td class="px-4 py-2 font-mono text-xs text-gray-700">{{ l.expense_account }}</td>
              <td class="px-4 py-2 text-gray-700">{{ l.description }}</td>
              <td class="px-4 py-2 text-right">{{ formatVnd(l.amount) }}</td>
              <td class="px-4 py-2 text-right text-gray-500">{{ l.vat_rate }}%</td>
              <td class="px-4 py-2 text-right text-gray-500">{{ formatVnd(l.vat_amount) }}</td>
              <td class="px-4 py-2 text-right font-medium">{{ formatVnd(l.net_amount) }}</td>
            </tr>
          </tbody>
          <tfoot class="border-t border-gray-200 bg-gray-50">
            <tr>
              <td colspan="2" class="px-4 py-2 text-xs font-semibold text-right text-gray-700">Tổng:</td>
              <td class="px-4 py-2 text-right font-bold">{{ formatVnd(report.total_amount) }}</td>
              <td></td>
              <td class="px-4 py-2 text-right font-semibold text-gray-700">{{ formatVnd(report.vat_amount) }}</td>
              <td class="px-4 py-2 text-right font-bold">{{ formatVnd(report.total_amount - report.vat_amount) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Reimburse modal -->
    <div v-if="showReimburseModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-xl p-6 max-w-sm w-full mx-4 shadow-xl space-y-4">
        <h3 class="font-semibold text-gray-900">Hoàn tiền cho người chi hộ</h3>
        <p class="text-sm text-gray-600">Số tiền hoàn: <strong>{{ formatVnd(report.total_amount) }}</strong></p>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Quỹ thanh toán <span class="text-red-500">*</span></label>
          <select v-model="reimburseForm.fund_id"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none">
            <option :value="null">-- Chọn quỹ --</option>
            <option v-for="f in funds" :key="f.id" :value="f.id">{{ f.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Diễn giải</label>
          <input v-model="reimburseForm.description" type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none" />
        </div>
        <div class="flex gap-3 justify-end">
          <button @click="showReimburseModal = false" class="px-4 py-2 border border-gray-300 rounded-lg text-sm">Hủy</button>
          <button @click="submitReimburse" :disabled="!reimburseForm.fund_id"
            class="px-4 py-2 bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white rounded-lg text-sm font-semibold">
            Xác nhận hoàn tiền
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ report: Object, funds: Array });
const { formatVnd } = useCurrency();

const showReimburseModal = ref(false);
const reimburseForm = ref({ fund_id: null, description: '' });

function statusClass(color) {
  const map = { gray: 'bg-gray-100 text-gray-600', blue: 'bg-blue-100 text-blue-700', green: 'bg-green-100 text-green-700' };
  return map[color] ?? 'bg-gray-100 text-gray-600';
}
function postReport() {
  router.post(route('accounting.personal-expenses.post', props.report.id));
}
function submitReimburse() {
  router.post(route('accounting.personal-expenses.reimburse', props.report.id), reimburseForm.value, {
    onSuccess: () => { showReimburseModal.value = false; },
  });
}
</script>
