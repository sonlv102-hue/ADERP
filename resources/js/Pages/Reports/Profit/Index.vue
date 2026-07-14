<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Báo cáo lợi nhuận</h1>
          <p class="text-sm text-gray-500 mt-0.5">Doanh thu, giá vốn, chi phí và lợi nhuận theo kỳ — tính từ bút toán GL đã ghi sổ</p>
        </div>
        <div class="flex items-center gap-2">
          <a :href="exportExcelUrl" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Xuất Excel
          </a>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-3 flex flex-wrap items-center gap-4">
        <div class="flex items-center gap-2">
          <label class="text-sm font-medium text-gray-600">Loại kỳ:</label>
          <select v-model="periodType" @change="applyFilters" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="month">Tháng</option>
            <option value="quarter">Quý</option>
            <option value="year">Năm</option>
            <option value="custom">Khoảng thời gian</option>
          </select>
        </div>

        <div v-if="periodType !== 'custom'" class="flex items-center gap-2">
          <label class="text-sm font-medium text-gray-600">Năm:</label>
          <select v-model="year" @change="applyFilters" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>

        <div v-if="periodType === 'month'" class="flex items-center gap-2">
          <label class="text-sm font-medium text-gray-600">Tháng:</label>
          <select v-model="month" @change="applyFilters" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option v-for="m in 12" :key="m" :value="m">Tháng {{ String(m).padStart(2, '0') }}</option>
          </select>
        </div>

        <div v-if="periodType === 'quarter'" class="flex items-center gap-2">
          <label class="text-sm font-medium text-gray-600">Quý:</label>
          <select v-model="quarter" @change="applyFilters" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option :value="1">Quý I</option>
            <option :value="2">Quý II</option>
            <option :value="3">Quý III</option>
            <option :value="4">Quý IV</option>
          </select>
        </div>

        <div v-if="periodType === 'custom'" class="flex items-center gap-2">
          <label class="text-sm font-medium text-gray-600">Từ ngày:</label>
          <input v-model="dateFrom" type="date" @change="applyFilters" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
          <label class="text-sm font-medium text-gray-600">Đến ngày:</label>
          <input v-model="dateTo" type="date" @change="applyFilters" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>

        <span class="text-sm text-gray-500 italic ml-auto">{{ period.label }}</span>
      </div>

      <!-- Cards tổng quan -->
      <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
          <p class="text-xs text-gray-500 font-medium mb-1">Doanh thu thuần</p>
          <p class="text-xl font-bold text-blue-700">{{ fmt(summary.net_revenue) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
          <p class="text-xs text-gray-500 font-medium mb-1">Giá vốn</p>
          <p class="text-xl font-bold text-amber-600">{{ fmt(summary.cogs) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
          <p class="text-xs text-gray-500 font-medium mb-1">Lợi nhuận gộp</p>
          <p class="text-xl font-bold text-green-700">{{ fmt(summary.gross_profit) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
          <p class="text-xs text-gray-500 font-medium mb-1">Tổng chi phí</p>
          <p class="text-xl font-bold text-red-600">{{ fmt(summary.total_operating_expense) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
          <p class="text-xs text-gray-500 font-medium mb-1">Lợi nhuận thuần</p>
          <p class="text-xl font-bold" :class="summary.net_profit >= 0 ? 'text-emerald-700' : 'text-red-700'">{{ fmt(summary.net_profit) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
          <p class="text-xs text-gray-500 font-medium mb-1">Biên lợi nhuận</p>
          <p class="text-xl font-bold text-slate-700">
            {{ fmtPercent(summary.gross_margin) }} gộp / {{ fmtPercent(summary.net_margin) }} thuần
          </p>
        </div>
      </div>

      <!-- Bảng chi tiết theo kỳ -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
        <div class="bg-slate-50 border-b border-gray-200 px-5 py-3.5">
          <h2 class="font-bold text-slate-800 text-sm uppercase tracking-wide">Chi tiết theo kỳ</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-700 text-white">
              <tr>
                <th class="px-3 py-3 font-semibold text-left">Kỳ</th>
                <th class="px-3 py-3 font-semibold text-right">Doanh thu</th>
                <th class="px-3 py-3 font-semibold text-right">Giá vốn</th>
                <th class="px-3 py-3 font-semibold text-right">LN gộp</th>
                <th class="px-3 py-3 font-semibold text-right">CP bán hàng</th>
                <th class="px-3 py-3 font-semibold text-right">CP quản lý</th>
                <th class="px-3 py-3 font-semibold text-right">CP tài chính</th>
                <th class="px-3 py-3 font-semibold text-right">CP khác</th>
                <th class="px-3 py-3 font-semibold text-right">LN thuần</th>
                <th class="px-3 py-3 font-semibold text-right">Tỷ suất %</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(row, idx) in rows" :key="idx" class="hover:bg-slate-50/50">
                <td class="px-3 py-2.5 font-medium text-slate-800">{{ row.label }}</td>
                <td class="px-3 py-2.5 text-right">{{ fmt(row.net_revenue) }}</td>
                <td class="px-3 py-2.5 text-right">{{ fmt(row.cogs) }}</td>
                <td class="px-3 py-2.5 text-right">{{ fmt(row.gross_profit) }}</td>
                <td class="px-3 py-2.5 text-right">{{ fmt(row.selling_expense) }}</td>
                <td class="px-3 py-2.5 text-right">{{ fmt(row.admin_expense) }}</td>
                <td class="px-3 py-2.5 text-right">{{ fmt(row.financial_expense) }}</td>
                <td class="px-3 py-2.5 text-right">{{ fmt(row.other_expense) }}</td>
                <td class="px-3 py-2.5 text-right font-medium" :class="row.net_profit >= 0 ? 'text-emerald-700' : 'text-red-700'">{{ fmt(row.net_profit) }}</td>
                <td class="px-3 py-2.5 text-right">{{ fmtPercent(row.net_margin) }}</td>
              </tr>
              <tr v-if="rows.length === 0">
                <td colspan="10" class="px-4 py-8 text-center text-gray-400 italic">Không có dữ liệu trong kỳ báo cáo này.</td>
              </tr>
            </tbody>
          </table>
        </div>
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
  summary:        Object,
  rows:           Array,
  period:         Object,
  filters:        Object,
  availableYears: Array,
});

const { formatVnd: fmt } = useCurrency();

const periodType = ref(props.filters.period_type || 'month');
const year       = ref(props.filters.year || new Date().getFullYear());
const month      = ref(props.filters.month || new Date().getMonth() + 1);
const quarter    = ref(props.filters.quarter || Math.ceil((new Date().getMonth() + 1) / 3));
const dateFrom   = ref(props.filters.date_from || new Date().toISOString().slice(0, 10));
const dateTo     = ref(props.filters.date_to || new Date().toISOString().slice(0, 10));

function fmtPercent(v) {
  return v === null || v === undefined ? '—' : `${v}%`;
}

const queryParams = computed(() => ({
  period_type: periodType.value,
  year:        year.value,
  month:       month.value,
  quarter:     quarter.value,
  date_from:   dateFrom.value,
  date_to:     dateTo.value,
}));

const exportExcelUrl = computed(() => {
  const q = new URLSearchParams(queryParams.value).toString();
  return route('reports.profit.export') + '?' + q;
});

function applyFilters() {
  router.get(route('reports.profit'), queryParams.value, { preserveState: true, replace: true });
}
</script>
