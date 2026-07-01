<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="erp-page-header">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Sổ nhật ký chung chi tiết</h1>
          <p class="text-sm text-gray-500 mt-0.5">Mỗi dòng là một dòng hạch toán (journal entry line) trong kỳ</p>
        </div>
        <a :href="exportUrl" class="erp-btn-secondary">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Xuất Excel
        </a>
      </div>

      <!-- Filter -->
      <div class="flex gap-3 items-center flex-wrap">
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600 font-medium">Năm:</label>
          <select v-model="year" @change="applyFilters" class="erp-input w-auto">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <span class="text-gray-400 text-sm">hoặc</span>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600">Từ</label>
          <input v-model="dateFrom" type="date" @change="year = null" class="erp-input w-auto" />
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600">Đến</label>
          <input v-model="dateTo" type="date" class="erp-input w-auto" />
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600 font-medium">Tài khoản:</label>
          <select v-model="accountCode" @change="applyFilters" class="erp-input w-auto">
            <option value="">Tất cả</option>
            <option v-for="(name, code) in accounts" :key="code" :value="code">{{ code }} – {{ name }}</option>
          </select>
        </div>
        <button @click="applyFilters" class="erp-btn-primary">Lọc</button>
      </div>

      <!-- Summary -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng bút toán / dòng</p>
          <p class="text-lg font-bold text-gray-900">{{ totalEntries }} / {{ total }}</p>
        </div>
        <div class="bg-white rounded-xl border border-blue-200 bg-blue-50 p-4">
          <p class="text-xs text-blue-600 mb-1">Tổng phát sinh Nợ</p>
          <p class="text-lg font-bold text-blue-800">{{ fmt(totalDebit) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-200 bg-green-50 p-4">
          <p class="text-xs text-green-600 mb-1">Tổng phát sinh Có</p>
          <p class="text-lg font-bold text-green-800">{{ fmt(totalCredit) }}</p>
        </div>
        <div class="bg-white rounded-xl border p-4" :class="difference === 0 ? 'border-gray-200' : 'border-red-300 bg-red-50'">
          <p class="text-xs mb-1" :class="difference === 0 ? 'text-gray-500' : 'text-red-600'">Chênh lệch</p>
          <p class="text-lg font-bold" :class="difference === 0 ? 'text-gray-900' : 'text-red-700'">{{ fmt(difference) }}</p>
        </div>
      </div>

      <div v-if="difference !== 0" class="bg-red-50 border border-red-300 text-red-700 text-sm rounded-lg px-4 py-2">
        Cảnh báo: Tổng phát sinh Nợ và Có không cân bằng. Vui lòng kiểm tra dữ liệu bút toán.
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="border-b border-gray-200 bg-gray-50">
            <tr>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs w-10">STT</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">Ngày</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">Chứng từ</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">Diễn giải bút toán</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">TK</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">Tên tài khoản</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">Diễn giải dòng</th>
              <th class="text-right px-3 py-2 font-semibold text-blue-600 text-xs">Nợ</th>
              <th class="text-right px-3 py-2 font-semibold text-green-600 text-xs">Có</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">Đối tượng</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">Dự án</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">Nguồn CT</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">Trạng thái</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">Người tạo</th>
              <th class="text-left px-3 py-2 font-semibold text-gray-600 text-xs">TG hạch toán</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="row in rows" :key="row.seq" class="hover:bg-gray-50">
              <td class="px-3 py-2 text-gray-400 text-xs">{{ row.seq }}</td>
              <td class="px-3 py-2 text-gray-600 text-xs whitespace-nowrap">{{ row.date }}</td>
              <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                <Link :href="route('accounting.journal-entries.show', row.journal_entry_id)"
                  class="text-primary-600 hover:underline">{{ row.ref }}</Link>
              </td>
              <td class="px-3 py-2 text-gray-700 text-xs">{{ row.entry_description }}</td>
              <td class="px-3 py-2 font-mono font-semibold text-xs whitespace-nowrap">{{ row.account_code }}</td>
              <td class="px-3 py-2 text-gray-700 text-xs">{{ row.account_name }}</td>
              <td class="px-3 py-2 text-gray-700 text-xs">{{ row.line_description }}</td>
              <td class="px-3 py-2 text-right font-medium text-blue-700 text-xs whitespace-nowrap">{{ row.debit ? fmt(row.debit) : '' }}</td>
              <td class="px-3 py-2 text-right font-medium text-green-700 text-xs whitespace-nowrap">{{ row.credit ? fmt(row.credit) : '' }}</td>
              <td class="px-3 py-2 text-gray-600 text-xs">{{ row.partner_name }}</td>
              <td class="px-3 py-2 text-gray-600 text-xs">{{ row.project_name }}</td>
              <td class="px-3 py-2 text-gray-600 text-xs">{{ row.source_type }}</td>
              <td class="px-3 py-2 text-xs"><StatusBadge :status="row.status">{{ statusLabel(row.status) }}</StatusBadge></td>
              <td class="px-3 py-2 text-gray-600 text-xs whitespace-nowrap">{{ row.created_by_name }}</td>
              <td class="px-3 py-2 text-gray-500 text-xs whitespace-nowrap">{{ row.posted_at }}</td>
            </tr>
            <tr v-if="rows.length === 0">
              <td colspan="15" class="px-4 py-8 text-center text-gray-400 text-sm">Không có dữ liệu</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="lastPage > 1" class="flex items-center justify-center gap-2">
        <button v-for="p in pageRange" :key="p" @click="goPage(p)"
          class="px-3 py-1 rounded text-sm"
          :class="p === currentPage ? 'bg-primary-600 text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'">
          {{ p }}
        </button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  accounts:     Object,
  rows:         Array,
  total:        Number,
  totalEntries: Number,
  totalDebit:   Number,
  totalCredit:  Number,
  difference:   Number,
  currentPage:  Number,
  lastPage:     Number,
  filters:      Object,
  currentYear:  Number,
});

const { formatVnd: fmt } = useCurrency();

const year        = ref(props.filters?.year         ?? props.currentYear);
const dateFrom    = ref(props.filters?.date_from    ?? '');
const dateTo      = ref(props.filters?.date_to      ?? '');
const accountCode = ref(props.filters?.account_code ?? '');

const yearOptions = computed(() => {
  const c = new Date().getFullYear();
  return [c - 2, c - 1, c, c + 1];
});

const pageRange = computed(() => {
  const pages = [];
  for (let i = Math.max(1, props.currentPage - 2); i <= Math.min(props.lastPage, props.currentPage + 2); i++) {
    pages.push(i);
  }
  return pages;
});

const exportUrl = computed(() => {
  const p = new URLSearchParams();
  if (year.value) p.set('year', year.value);
  if (dateFrom.value) p.set('date_from', dateFrom.value);
  if (dateTo.value) p.set('date_to', dateTo.value);
  if (accountCode.value) p.set('account_code', accountCode.value);
  return route('reports.general_journal_detail.export') + '?' + p.toString();
});

function buildParams(page) {
  return {
    year:         year.value        || undefined,
    date_from:    dateFrom.value    || undefined,
    date_to:      dateTo.value      || undefined,
    account_code: accountCode.value || undefined,
    page,
  };
}

function applyFilters() {
  router.get(route('reports.general_journal_detail'), buildParams(1), { preserveState: true, replace: true });
}

function goPage(p) {
  router.get(route('reports.general_journal_detail'), buildParams(p), { preserveState: true, replace: true });
}

function statusLabel(status) {
  return { draft: 'Nháp', posted: 'Đã hạch toán', reversed: 'Đã đảo', voided: 'Đã hủy' }[status] ?? status;
}
</script>
