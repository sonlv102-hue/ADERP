<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Báo cáo VAT</h1>
          <p class="text-sm text-gray-500 mt-0.5">VAT đầu ra (bán hàng) − VAT đầu vào (mua hàng) = VAT phải nộp</p>
        </div>
        <a :href="exportUrl" class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Xuất Excel
        </a>
      </div>

      <!-- Filter năm -->
      <div class="flex gap-3 items-center">
        <label class="text-sm text-gray-600 font-medium">Năm:</label>
        <select v-model="year" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>

      <!-- Summary cards -->
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng VAT đầu ra</p>
          <p class="text-lg font-bold text-blue-700">{{ fmt(summary.total_vat_out) }}</p>
          <p class="text-xs text-gray-400 mt-0.5">Doanh thu: {{ fmt(summary.total_revenue) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng VAT đầu vào</p>
          <p class="text-lg font-bold text-orange-700">{{ fmt(summary.total_vat_in) }}</p>
          <p class="text-xs text-gray-400 mt-0.5">Mua vào: {{ fmt(summary.total_purchase) }}</p>
        </div>
        <div class="bg-white rounded-xl border p-4" :class="summary.total_payable >= 0 ? 'border-red-200 bg-red-50' : 'border-green-200 bg-green-50'">
          <p class="text-xs mb-1" :class="summary.total_payable >= 0 ? 'text-red-600' : 'text-green-600'">VAT phải nộp cả năm</p>
          <p class="text-lg font-bold" :class="summary.total_payable >= 0 ? 'text-red-700' : 'text-green-700'">
            {{ fmt(Math.abs(summary.total_payable)) }}
            <span class="text-sm font-normal">{{ summary.total_payable < 0 ? '(được hoàn)' : '' }}</span>
          </p>
        </div>
      </div>

      <!-- Monthly table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 font-semibold text-gray-600">Tháng</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Doanh thu (chưa VAT)</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">VAT đầu ra</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">Mua vào (chưa VAT)</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">VAT đầu vào</th>
              <th class="text-right px-4 py-3 font-semibold text-gray-600">VAT phải nộp</th>
              <th class="text-center px-4 py-3 font-semibold text-gray-600">Chi tiết</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template v-for="row in months" :key="row.month">
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-800">Tháng {{ row.month }}</td>
                <td class="px-4 py-3 text-right text-gray-600">{{ fmt(row.revenue) }}</td>
                <td class="px-4 py-3 text-right text-blue-700 font-medium">{{ fmt(row.vat_out) }}</td>
                <td class="px-4 py-3 text-right text-gray-600">{{ fmt(row.purchase) }}</td>
                <td class="px-4 py-3 text-right text-orange-700 font-medium">{{ fmt(row.vat_in) }}</td>
                <td class="px-4 py-3 text-right font-bold" :class="row.payable >= 0 ? 'text-red-700' : 'text-green-700'">
                  {{ fmt(Math.abs(row.payable)) }}
                  <span v-if="row.payable < 0" class="text-xs font-normal">(hoàn)</span>
                </td>
                <td class="px-4 py-3 text-center">
                  <button v-if="row.vat_out > 0 || row.vat_in > 0"
                    @click="toggleDetail(row.month)"
                    class="text-xs text-primary-600 hover:underline">
                    {{ detailMonth === row.month ? 'Ẩn' : 'Xem' }}
                  </button>
                  <span v-else class="text-gray-300 text-xs">—</span>
                </td>
              </tr>
              <!-- Detail rows -->
              <tr v-if="detailMonth === row.month && details.length">
                <td colspan="7" class="p-0">
                  <div class="bg-gray-50 border-t border-b border-gray-200 px-6 py-3">
                    <div class="flex gap-3 mb-2">
                      <button @click="switchDetail('out')" :class="detailType === 'out' ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 border border-gray-300'" class="px-3 py-1 rounded text-xs font-medium">
                        Hóa đơn bán (đầu ra)
                      </button>
                      <button @click="switchDetail('in')" :class="detailType === 'in' ? 'bg-orange-600 text-white' : 'bg-white text-gray-700 border border-gray-300'" class="px-3 py-1 rounded text-xs font-medium">
                        Hóa đơn mua (đầu vào)
                      </button>
                    </div>
                    <table class="w-full text-xs">
                      <thead>
                        <tr class="text-gray-500">
                          <th class="text-left py-1 pr-4">Số HĐ</th>
                          <th class="text-left py-1 pr-4">{{ detailType === 'out' ? 'Khách hàng' : 'Nhà cung cấp' }}</th>
                          <th class="text-left py-1 pr-4">Ngày</th>
                          <th class="text-right py-1 pr-4">Doanh thu/Mua</th>
                          <th class="text-right py-1">VAT</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="d in details" :key="d.code" class="border-t border-gray-200">
                          <td class="py-1 pr-4 font-mono text-primary-700">{{ d.code }}</td>
                          <td class="py-1 pr-4">{{ d.party }}</td>
                          <td class="py-1 pr-4 text-gray-500">{{ fmtDate(d.doc_date) }}</td>
                          <td class="py-1 pr-4 text-right">{{ fmt(d.subtotal) }}</td>
                          <td class="py-1 text-right font-medium" :class="detailType === 'out' ? 'text-blue-700' : 'text-orange-700'">
                            {{ fmt(d.tax_amount) }}
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot class="bg-gray-50 border-t-2 border-gray-300">
            <tr>
              <td class="px-4 py-3 font-semibold text-gray-700">Cả năm {{ currentYear }}</td>
              <td class="px-4 py-3 text-right font-semibold text-gray-800">{{ fmt(summary.total_revenue) }}</td>
              <td class="px-4 py-3 text-right font-bold text-blue-700">{{ fmt(summary.total_vat_out) }}</td>
              <td class="px-4 py-3 text-right font-semibold text-gray-800">{{ fmt(summary.total_purchase) }}</td>
              <td class="px-4 py-3 text-right font-bold text-orange-700">{{ fmt(summary.total_vat_in) }}</td>
              <td class="px-4 py-3 text-right font-bold" :class="summary.total_payable >= 0 ? 'text-red-700' : 'text-green-700'">
                {{ fmt(Math.abs(summary.total_payable)) }}
              </td>
              <td></td>
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
  months:      Array,
  summary:     Object,
  details:     Array,
  filters:     Object,
  currentYear: Number,
});

const { formatVnd: fmt } = useCurrency();

const year        = ref(props.filters?.year ?? props.currentYear);
const detailMonth = ref(props.filters?.detail_month ? parseInt(props.filters.detail_month) : 0);
const detailType  = ref(props.filters?.detail_type  ?? 'out');

const yearOptions = computed(() => {
  const current = new Date().getFullYear();
  return [current - 2, current - 1, current, current + 1];
});

const exportUrl = computed(() => route('reports.vat.export') + '?year=' + year.value);

function applyFilters(opts = {}) {
  router.get(route('reports.vat'), {
    year:         year.value,
    detail_month: opts.detail_month ?? (detailMonth.value || undefined),
    detail_type:  opts.detail_type  ?? (detailType.value  || undefined),
  }, { preserveState: true, replace: true });
}

function toggleDetail(month) {
  if (detailMonth.value === month) {
    detailMonth.value = 0;
    applyFilters({ detail_month: undefined });
  } else {
    detailMonth.value = month;
    applyFilters({ detail_month: month, detail_type: detailType.value });
  }
}

function switchDetail(type) {
  detailType.value = type;
  applyFilters({ detail_month: detailMonth.value, detail_type: type });
}

function fmtDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('vi-VN');
}
</script>
