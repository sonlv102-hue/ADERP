<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Chi tiết tài khoản chi phí</h1>
          <p class="text-sm text-gray-500 mt-0.5">TK 632 Giá vốn · TK 6421 Bán hàng · TK 6422 QLDN</p>
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

      <!-- KPI -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-red-200 bg-red-50 p-4">
          <p class="text-xs text-red-600 mb-1">TK 632 – Giá vốn</p>
          <p class="text-lg font-bold text-red-700">{{ fmt(summary.total_632) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-orange-200 bg-orange-50 p-4">
          <p class="text-xs text-orange-600 mb-1">TK 6421 – Chi phí bán hàng (TT133)</p>
          <p class="text-lg font-bold text-orange-700">{{ fmt(summary.total_6421) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-yellow-200 bg-yellow-50 p-4">
          <p class="text-xs text-yellow-600 mb-1">TK 642 – Chi phí QLDN</p>
          <p class="text-lg font-bold text-yellow-700">{{ fmt(summary.total_642) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng chi phí</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(summary.grand_total) }}</p>
        </div>
      </div>

      <!-- Groups -->
      <div v-for="group in groups" :key="group.name" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="bg-gray-50 border-b border-gray-200 px-5 py-3 flex items-center justify-between">
          <h2 class="font-semibold text-gray-800">
            <span class="font-mono text-primary-700 mr-2">TK {{ group.tk }}</span>{{ group.name }}
          </h2>
          <span class="text-sm font-bold text-red-700">{{ fmt(group.total) }}</span>
        </div>
        <table class="w-full text-sm">
          <thead class="border-b border-gray-100 bg-gray-50">
            <tr>
              <th class="text-left px-4 py-2 font-semibold text-gray-500 text-xs">Ngày</th>
              <th class="text-left px-4 py-2 font-semibold text-gray-500 text-xs">Chứng từ</th>
              <th class="text-left px-4 py-2 font-semibold text-gray-500 text-xs">Diễn giải</th>
              <th class="text-right px-4 py-2 font-semibold text-gray-500 text-xs">Số tiền</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(row, i) in group.rows" :key="i" class="hover:bg-gray-50">
              <td class="px-4 py-2 text-gray-600 text-xs">{{ row.date }}</td>
              <td class="px-4 py-2 font-mono text-gray-700 text-xs">{{ row.ref }}</td>
              <td class="px-4 py-2 text-gray-700 text-xs">{{ row.description }}</td>
              <td class="px-4 py-2 text-right text-red-700 font-medium text-xs">{{ fmt(row.amount) }}</td>
            </tr>
            <tr v-if="group.rows.length === 0">
              <td colspan="4" class="px-4 py-4 text-center text-gray-400 text-xs">Không có dữ liệu</td>
            </tr>
          </tbody>
          <tfoot class="bg-gray-50 border-t border-gray-200">
            <tr>
              <td colspan="3" class="px-4 py-2 font-semibold text-gray-700 text-xs">Tổng cộng</td>
              <td class="px-4 py-2 text-right font-bold text-red-700 text-xs">{{ fmt(group.total) }}</td>
            </tr>
          </tfoot>
        </table>
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
  groups:      Array,
  summary:     Object,
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

const exportUrl = computed(() => {
  const p = new URLSearchParams();
  if (year.value) p.set('year', year.value);
  if (dateFrom.value) p.set('date_from', dateFrom.value);
  if (dateTo.value)   p.set('date_to', dateTo.value);
  return route('reports.expense_detail.export') + '?' + p.toString();
});

function applyFilters() {
  router.get(route('reports.expense_detail'), {
    year:      year.value     || undefined,
    date_from: dateFrom.value || undefined,
    date_to:   dateTo.value   || undefined,
  }, { preserveState: true, replace: true });
}
</script>
