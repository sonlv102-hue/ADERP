<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Sổ nhật ký chung</h1>
          <p class="text-sm text-gray-500 mt-0.5">Ghi chép các bút toán theo thứ tự thời gian</p>
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

      <!-- Summary -->
      <div class="grid grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng bút toán</p>
          <p class="text-lg font-bold text-gray-900">{{ total }}</p>
        </div>
        <div class="bg-white rounded-xl border border-blue-200 bg-blue-50 p-4">
          <p class="text-xs text-blue-600 mb-1">Tổng phát sinh Nợ</p>
          <p class="text-lg font-bold text-blue-800">{{ fmt(totalDebit) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-200 bg-green-50 p-4">
          <p class="text-xs text-green-600 mb-1">Tổng phát sinh Có</p>
          <p class="text-lg font-bold text-green-800">{{ fmt(totalCredit) }}</p>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50">
              <tr>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs w-10">STT</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Ngày</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Chứng từ</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Diễn giải</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Đối tác</th>
                <th class="text-center px-4 py-2 font-semibold text-blue-600 text-xs">TK Nợ</th>
                <th class="text-center px-4 py-2 font-semibold text-green-600 text-xs">TK Có</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Số tiền</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="row in entries" :key="row.seq" class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-400 text-xs">{{ row.seq }}</td>
                <td class="px-4 py-2 text-gray-600 text-xs">{{ row.date }}</td>
                <td class="px-4 py-2 font-mono text-gray-700 text-xs">{{ row.ref }}</td>
                <td class="px-4 py-2 text-gray-700 text-xs">{{ row.description }}</td>
                <td class="px-4 py-2 text-gray-600 text-xs">{{ row.partner }}</td>
                <td class="px-4 py-2 text-center font-mono font-semibold text-blue-700 text-xs">{{ row.debit_tk }}</td>
                <td class="px-4 py-2 text-center font-mono font-semibold text-green-700 text-xs">{{ row.credit_tk }}</td>
                <td class="px-4 py-2 text-right font-medium text-gray-800 text-xs">{{ fmt(row.amount) }}</td>
              </tr>
              <tr v-if="entries.length === 0">
                <td colspan="8" class="px-4 py-8 text-center text-gray-400 text-sm">Không có dữ liệu</td>
              </tr>
            </tbody>
          </table>
        </div>
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
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  entries:     Array,
  total:       Number,
  totalDebit:  Number,
  totalCredit: Number,
  currentPage: Number,
  lastPage:    Number,
  filters:     Object,
  currentYear: Number,
});

const { formatVnd: fmt } = useCurrency();

const year     = ref(props.filters?.year      ?? props.currentYear);
const dateFrom = ref(props.filters?.date_from ?? '');
const dateTo   = ref(props.filters?.date_to   ?? '');

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
  if (dateTo.value)   p.set('date_to', dateTo.value);
  return route('reports.general_journal.export') + '?' + p.toString();
});

function applyFilters() {
  router.get(route('reports.general_journal'), {
    year:      year.value     || undefined,
    date_from: dateFrom.value || undefined,
    date_to:   dateTo.value   || undefined,
    page:      1,
  }, { preserveState: true, replace: true });
}

function goPage(p) {
  router.get(route('reports.general_journal'), {
    year:      year.value     || undefined,
    date_from: dateFrom.value || undefined,
    date_to:   dateTo.value   || undefined,
    page:      p,
  }, { preserveState: true, replace: true });
}
</script>
