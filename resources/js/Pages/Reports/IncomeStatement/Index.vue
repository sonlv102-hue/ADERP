<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Kết quả Hoạt động Kinh doanh</h1>
          <p class="text-sm text-gray-500 mt-0.5">Doanh thu − Giá vốn = Lợi nhuận gộp (tính từ hóa đơn và chi phí dự án)</p>
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
        <span class="text-gray-400 text-sm">hoặc chọn khoảng:</span>
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
        <button @click="applyFilters"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Cập nhật</button>
      </div>

      <!-- KPI cards -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Doanh thu</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(summary.revenue) }}</p>
          <p class="text-xs text-gray-400 mt-0.5">VAT đầu ra: {{ fmt(summary.vat_out) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-200 bg-red-50 p-4">
          <p class="text-xs text-red-600 mb-1">Giá vốn</p>
          <p class="text-lg font-bold text-red-700">{{ fmt(summary.total_cogs) }}</p>
        </div>
        <div class="bg-white rounded-xl border p-4" :class="summary.gross_profit >= 0 ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'">
          <p class="text-xs mb-1" :class="summary.gross_profit >= 0 ? 'text-green-600' : 'text-red-600'">Lợi nhuận gộp</p>
          <p class="text-lg font-bold" :class="summary.gross_profit >= 0 ? 'text-green-700' : 'text-red-700'">{{ fmt(summary.gross_profit) }}</p>
          <p class="text-xs mt-0.5" :class="summary.gross_profit >= 0 ? 'text-green-500' : 'text-red-500'">
            {{ summary.gross_margin !== null ? summary.gross_margin + '%' : '—' }}
          </p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Mua hàng (chưa VAT)</p>
          <p class="text-lg font-bold text-gray-700">{{ fmt(summary.purchase_total) }}</p>
          <p class="text-xs text-gray-400 mt-0.5">VAT đầu vào: {{ fmt(summary.vat_in) }}</p>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <!-- P&L Statement -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="bg-gray-50 border-b border-gray-200 px-5 py-3">
            <h2 class="font-semibold text-gray-800">Báo cáo kết quả HĐKD — {{ currentYear }}</h2>
          </div>
          <table class="w-full text-sm">
            <tbody>
              <tr v-for="(line, i) in statement" :key="i"
                class="border-b border-gray-100 last:border-0"
                :class="line.bold ? 'bg-gray-50' : 'hover:bg-gray-50'">
                <td class="px-5 py-2.5 text-gray-700" :class="['font-normal', 'font-normal pl-8', 'font-normal pl-12'][line.indent] ?? 'font-normal'">
                  <span :class="line.bold ? 'font-semibold text-gray-900' : ''">{{ line.label }}</span>
                </td>
                <td class="px-5 py-2.5 text-right font-medium"
                  :class="{
                    'font-bold text-gray-900': line.bold,
                    'text-green-700': !line.bold && line.amount > 0,
                    'text-red-700':   !line.bold && line.amount < 0,
                    'text-gray-400':  line.amount === 0,
                  }">
                  {{ line.amount !== 0 ? fmt(Math.abs(line.amount)) : '—' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Monthly breakdown -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="bg-gray-50 border-b border-gray-200 px-5 py-3">
            <h2 class="font-semibold text-gray-800">Phân tích theo tháng — {{ currentYear }}</h2>
          </div>
          <table class="w-full text-sm">
            <thead class="border-b border-gray-200">
              <tr>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Tháng</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Doanh thu</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Giá vốn</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">LN gộp</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="row in monthly" :key="row.month" class="hover:bg-gray-50">
                <td class="px-4 py-2 text-gray-700 font-medium text-xs">T{{ row.month }}</td>
                <td class="px-4 py-2 text-right text-gray-700 text-xs">{{ row.revenue > 0 ? fmt(row.revenue) : '—' }}</td>
                <td class="px-4 py-2 text-right text-red-600 text-xs">{{ row.cogs > 0 ? fmt(row.cogs) : '—' }}</td>
                <td class="px-4 py-2 text-right text-xs font-semibold"
                  :class="row.gross_profit > 0 ? 'text-green-700' : row.gross_profit < 0 ? 'text-red-700' : 'text-gray-400'">
                  {{ row.gross_profit !== 0 ? fmt(row.gross_profit) : '—' }}
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 border-t-2 border-gray-300">
              <tr>
                <td class="px-4 py-2 font-semibold text-gray-700 text-xs">Cả năm</td>
                <td class="px-4 py-2 text-right font-bold text-gray-800 text-xs">{{ fmt(summary.revenue) }}</td>
                <td class="px-4 py-2 text-right font-bold text-red-700 text-xs">{{ fmt(summary.total_cogs) }}</td>
                <td class="px-4 py-2 text-right font-bold text-xs" :class="summary.gross_profit >= 0 ? 'text-green-700' : 'text-red-700'">
                  {{ fmt(summary.gross_profit) }}
                </td>
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
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  statement:   Array,
  monthly:     Array,
  summary:     Object,
  filters:     Object,
  currentYear: Number,
});

const { formatVnd: fmt } = useCurrency();

const year     = ref(props.filters?.year      ?? props.currentYear);
const dateFrom = ref(props.filters?.date_from ?? '');
const dateTo   = ref(props.filters?.date_to   ?? '');

const yearOptions = computed(() => {
  const current = new Date().getFullYear();
  return [current - 2, current - 1, current, current + 1];
});

const exportUrl = computed(() => {
  const params = new URLSearchParams();
  params.set('year', year.value ?? props.currentYear);
  if (dateFrom.value) params.set('date_from', dateFrom.value);
  if (dateTo.value)   params.set('date_to',   dateTo.value);
  return route('reports.income_statement.export') + '?' + params.toString();
});

function applyFilters() {
  router.get(route('reports.income_statement'), {
    year:      year.value     || undefined,
    date_from: dateFrom.value || undefined,
    date_to:   dateTo.value   || undefined,
  }, { preserveState: true, replace: true });
}
</script>
