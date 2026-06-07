<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Sổ chi tiết tài khoản {{ account }}</h1>
          <p class="text-sm text-gray-500 mt-0.5">{{ accountName }}</p>
        </div>
        <a :href="exportUrl" class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Xuất Excel
        </a>
      </div>

      <!-- Filter -->
      <div class="flex gap-3 items-center flex-wrap">
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600 font-medium">Tài khoản:</label>
          <select v-model="selectedAccount" @change="applyFilters"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option v-for="(name, code) in accounts" :key="code" :value="code">TK {{ code }} – {{ name }}</option>
          </select>
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600 font-medium">Năm:</label>
          <select v-model="year" @change="applyFilters"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <span class="text-gray-400 text-sm">hoặc</span>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600">Từ</label>
          <input v-model="dateFrom" type="date" @change="year = null"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600">Đến</label>
          <input v-model="dateTo" type="date"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>
        <button @click="applyFilters" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Cập nhật</button>
      </div>

      <!-- KPI -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Số dư đầu kỳ</p>
          <p class="text-lg font-bold" :class="openingBalance >= 0 ? 'text-gray-900' : 'text-red-700'">{{ fmt(openingBalance) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-blue-200 bg-blue-50 p-4">
          <p class="text-xs text-blue-600 mb-1">Phát sinh Nợ</p>
          <p class="text-lg font-bold text-blue-800">{{ fmt(totalDebit) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-200 bg-green-50 p-4">
          <p class="text-xs text-green-600 mb-1">Phát sinh Có</p>
          <p class="text-lg font-bold text-green-800">{{ fmt(totalCredit) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Số dư cuối kỳ</p>
          <p class="text-lg font-bold" :class="closingBalance >= 0 ? 'text-gray-900' : 'text-red-700'">{{ fmt(closingBalance) }}</p>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50">
              <tr>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Ngày</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Chứng từ</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Diễn giải</th>
                <th class="text-right px-4 py-2 font-semibold text-blue-600 text-xs">Nợ</th>
                <th class="text-right px-4 py-2 font-semibold text-green-600 text-xs">Có</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Số dư</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr class="bg-yellow-50 font-semibold">
                <td colspan="3" class="px-4 py-2 text-gray-700 text-xs">Số dư đầu kỳ</td>
                <td class="px-4 py-2 text-right text-xs"></td>
                <td class="px-4 py-2 text-right text-xs"></td>
                <td class="px-4 py-2 text-right font-semibold text-gray-800 text-xs">{{ fmt(openingBalance) }}</td>
              </tr>
              <tr v-for="(row, i) in rows" :key="i" class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-600 text-xs">{{ row.date }}</td>
                <td class="px-4 py-2 font-mono text-xs">
                  <Link :href="route('accounting.journal-entries.show', row.journal_entry_id)"
                    class="text-primary-600 hover:underline">{{ row.ref }}</Link>
                </td>
                <td class="px-4 py-2 text-gray-700 text-xs">{{ row.description }}</td>
                <td class="px-4 py-2 text-right text-blue-700 text-xs">{{ row.debit > 0 ? fmt(row.debit) : '—' }}</td>
                <td class="px-4 py-2 text-right text-green-700 text-xs">{{ row.credit > 0 ? fmt(row.credit) : '—' }}</td>
                <td class="px-4 py-2 text-right font-semibold text-xs"
                  :class="row.balance >= 0 ? 'text-gray-800' : 'text-red-700'">{{ fmt(row.balance) }}</td>
              </tr>
              <tr v-if="rows.length === 0">
                <td colspan="6" class="px-4 py-8 text-center text-gray-400 text-sm">Không có phát sinh trong kỳ</td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 border-t-2 border-gray-300">
              <tr>
                <td colspan="3" class="px-4 py-2 font-bold text-gray-800 text-xs">Số dư cuối kỳ</td>
                <td class="px-4 py-2 text-right font-bold text-blue-800 text-xs">{{ fmt(totalDebit) }}</td>
                <td class="px-4 py-2 text-right font-bold text-green-800 text-xs">{{ fmt(totalCredit) }}</td>
                <td class="px-4 py-2 text-right font-bold text-xs"
                  :class="closingBalance >= 0 ? 'text-gray-800' : 'text-red-700'">{{ fmt(closingBalance) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  rows:           Array,
  openingBalance: Number,
  closingBalance: Number,
  totalDebit:     Number,
  totalCredit:    Number,
  account:        String,
  accountName:    String,
  accounts:       Object,
  filters:        Object,
  currentYear:    Number,
});

const { formatVnd: fmt } = useCurrency();

const selectedAccount = ref(props.filters?.account ?? '131');
const year            = ref(props.filters?.year    ?? props.currentYear);
const dateFrom        = ref(props.filters?.date_from ?? '');
const dateTo          = ref(props.filters?.date_to   ?? '');

const yearOptions = computed(() => {
  const c = new Date().getFullYear();
  return [c - 2, c - 1, c, c + 1];
});

const exportUrl = computed(() => {
  const p = new URLSearchParams();
  p.set('account', selectedAccount.value);
  if (year.value) p.set('year', year.value);
  if (dateFrom.value) p.set('date_from', dateFrom.value);
  if (dateTo.value)   p.set('date_to', dateTo.value);
  return route('reports.account_ledger.export') + '?' + p.toString();
});

function applyFilters() {
  router.get(route('reports.account_ledger'), {
    account:   selectedAccount.value,
    year:      year.value      || undefined,
    date_from: dateFrom.value  || undefined,
    date_to:   dateTo.value    || undefined,
  }, { preserveState: true, replace: true });
}
</script>
