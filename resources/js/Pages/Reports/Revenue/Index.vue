<template>
  <AppLayout>
    <div class="space-y-5 print:p-0 print:space-y-0">
      <!-- Page Header (Web) -->
      <div class="flex items-center justify-between flex-wrap gap-3 print:hidden">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Báo cáo Doanh thu</h1>
          <p class="text-sm text-gray-500 mt-0.5">Theo dõi doanh thu từ hóa đơn bán hàng và đối chiếu với Sổ cái kế toán</p>
        </div>
        <div class="flex items-center gap-2">
          <a :href="exportExcelUrl" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Excel
          </a>
          <a :href="exportPdfUrl" target="_blank" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m.75 12l3 3m0 0l3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
            </svg>
            Tải PDF
          </a>
          <button @click="printReport" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.615 0-1.101-.482-1.12-1.08L5.82 18m12.06 0l-.822-4.003m-10.417 4.003L5.43 13.83c-.314-.064-.627-.134-.938-.21m15.016 0c.314-.064.627-.134.938-.21m-1.464-3.518l.822 4.003m-12.91-4.003L3.17 9.87a1.875 1.875 0 010-3.328l6.905-3.322a3.75 3.75 0 013.85 0l6.905 3.322a1.875 1.875 0 010 3.328l-1.378.662m-13.868 2.69L5.43 13.83m10.94-4.17L18.57 9.87" />
            </svg>
            In báo cáo
          </button>
        </div>
      </div>

      <!-- Filters (Web) -->
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-3 flex flex-wrap items-center gap-4 print:hidden">
        <div class="flex items-center gap-2">
          <label class="text-sm font-medium text-gray-600">Kỳ báo cáo:</label>
          <select v-model="periodType" @change="applyFilters" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
            <option value="month">Theo tháng</option>
            <option value="quarter">Theo quý</option>
          </select>
        </div>

        <div class="flex items-center gap-2">
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
      </div>

      <!-- Header (In) -->
      <div class="hidden print:block">
        <div class="flex justify-between items-start mb-4">
          <div class="text-sm">
            <div class="font-bold text-gray-900 uppercase text-[11px]">{{ company.company_name }}</div>
            <div class="text-gray-600 text-[10px]">Địa chỉ: {{ company.company_address }}</div>
            <div v-if="company.company_phone" class="text-gray-600 text-[10px]">SĐT: {{ company.company_phone }}</div>
          </div>
          <div class="text-right text-[9px] text-gray-400 italic">
            <div>Mẫu báo cáo nội bộ</div>
            <div>Hệ thống MiniERP</div>
          </div>
        </div>
        <h2 class="text-center text-xl font-bold uppercase tracking-wide mb-1 mt-6">Báo cáo Doanh thu</h2>
        <p class="text-center text-sm italic mb-2 text-gray-600">Kỳ báo cáo: {{ periodLabel }}</p>
        <div class="flex justify-between text-[10px] text-gray-500 italic mb-3">
          <div>Nguồn dữ liệu: Hóa đơn đã xác nhận / hạch toán</div>
          <div>Ngày xuất: {{ formatCurrentDate() }}</div>
        </div>
      </div>

      <!-- Summary Cards (Web) -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 print:hidden">
        <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
          <p class="text-xs text-gray-500 font-medium mb-1">Doanh thu chưa VAT</p>
          <p class="text-xl font-bold text-blue-700">{{ fmt(summary.total_subtotal) }}</p>
          <p class="text-xs text-gray-400 mt-1">Từ {{ summary.count_invoices }} hóa đơn</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
          <p class="text-xs text-gray-500 font-medium mb-1">Thuế GTGT đầu ra</p>
          <p class="text-xl font-bold text-amber-600">{{ fmt(summary.total_tax) }}</p>
          <p class="text-xs text-gray-400 mt-1">Tài khoản hạch toán: 3331</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
          <p class="text-xs text-gray-500 font-medium mb-1">Tổng thanh toán</p>
          <p class="text-xl font-bold text-green-700">{{ fmt(summary.total_payment) }}</p>
          <p class="text-xs text-gray-400 mt-1">Đã bao gồm VAT</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
          <p class="text-xs text-gray-500 font-medium mb-1">Số chứng từ</p>
          <p class="text-xl font-bold text-slate-700">{{ summary.count_invoices }}</p>
          <p class="text-xs text-gray-400 mt-1">Hóa đơn hợp lệ trong kỳ</p>
        </div>
      </div>

      <!-- Reconciliation Section (Web & In) -->
      <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm print:border-0 print:shadow-none print:p-0 print:mt-4 print:break-inside-avoid">
        <h3 class="text-base font-bold text-slate-800 mb-3 uppercase tracking-wide print:text-[11px] print:text-gray-900 print:mb-2">
          Đối chiếu số liệu với Sổ cái kế toán (Bút toán đã ghi sổ)
        </h3>

        <!-- Cảnh báo nếu có lệch -->
        <div v-if="!gl_reconcile.has_gl_entries" class="mb-4 bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800 flex items-start gap-2.5 print:hidden">
          <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <div>
            <p class="font-semibold">Chưa phát hiện bút toán ghi sổ nào!</p>
            <p class="text-xs text-amber-700 mt-0.5">Không tìm thấy bút toán đã ghi sổ (Posted) nào trong kỳ báo cáo. Vui lòng kiểm tra lại trạng thái các chứng từ bán hàng hoặc kỳ kế toán.</p>
          </div>
        </div>
        <div v-else-if="gl_reconcile.revenue_diff !== 0 || gl_reconcile.vat_diff !== 0" class="mb-4 bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-800 flex items-start gap-2.5 print:hidden">
          <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <div>
            <p class="font-semibold">Lệch số liệu hạch toán!</p>
            <p class="text-xs text-red-700 mt-0.5">Phát hiện sự chênh lệch giữa số liệu trên hóa đơn và số liệu đã ghi sổ cái kế toán (TK 511 / TK 3331). Vui lòng đối soát chi tiết.</p>
          </div>
        </div>
        <div v-else class="mb-4 bg-green-50 border border-green-200 rounded-lg p-3 text-sm text-green-800 flex items-start gap-2.5 print:hidden">
          <svg class="w-5 h-5 text-green-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <p class="font-semibold">Số liệu khớp hoàn toàn!</p>
            <p class="text-xs text-green-700 mt-0.5">Số liệu doanh thu và thuế GTGT đầu ra của hóa đơn bán hàng trùng khớp hoàn toàn với số phát sinh trên Sổ cái.</p>
          </div>
        </div>

        <!-- Bảng đối chiếu -->
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm border border-slate-200 rounded-lg overflow-hidden print:border-gray-300 print:text-[10px]">
            <thead class="bg-slate-50 border-b border-slate-200 print:bg-gray-100 print:border-gray-300">
              <tr>
                <th class="text-left px-4 py-3 font-semibold text-slate-700 print:text-gray-900 print:py-2">Chỉ tiêu đối chiếu</th>
                <th class="text-right px-4 py-3 font-semibold text-slate-700 print:text-gray-900 print:py-2">Số liệu hóa đơn (1)</th>
                <th class="text-right px-4 py-3 font-semibold text-slate-700 print:text-gray-900 print:py-2">Số liệu Sổ cái GL (2)</th>
                <th class="text-right px-4 py-3 font-semibold text-slate-700 print:text-gray-900 print:py-2">Chênh lệch (1 - 2)</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 print:divide-gray-200">
              <tr>
                <td class="px-4 py-3 text-slate-800 font-medium print:py-2 print:text-gray-900">1. Doanh thu (Tài khoản 511)</td>
                <td class="px-4 py-3 text-right text-slate-600 print:py-2">{{ fmt(summary.total_subtotal) }}</td>
                <td class="px-4 py-3 text-right text-slate-600 print:py-2">{{ fmt(gl_reconcile.gl_revenue) }}</td>
                <td class="px-4 py-3 text-right font-bold print:py-2" :class="gl_reconcile.revenue_diff === 0 ? 'text-green-700' : 'text-red-700'">
                  {{ formatDiff(gl_reconcile.revenue_diff) }}
                </td>
              </tr>
              <tr>
                <td class="px-4 py-3 text-slate-800 font-medium print:py-2 print:text-gray-900">2. Thuế GTGT đầu ra (Tài khoản 3331)</td>
                <td class="px-4 py-3 text-right text-slate-600 print:py-2">{{ fmt(summary.total_tax) }}</td>
                <td class="px-4 py-3 text-right text-slate-600 print:py-2">{{ fmt(gl_reconcile.gl_vat) }}</td>
                <td class="px-4 py-3 text-right font-bold print:py-2" :class="gl_reconcile.vat_diff === 0 ? 'text-green-700' : 'text-red-700'">
                  {{ formatDiff(gl_reconcile.vat_diff) }}
                </td>
              </tr>
            </tbody>
          </table>
          <p class="hidden print:block text-[9px] text-gray-500 italic mt-2">
            * Ghi chú: Số phát sinh đối chiếu trên Sổ cái được tính từ các bút toán GL ở trạng thái Đã ghi sổ (Posted).
          </p>
        </div>
      </div>

      <!-- Bảng chi tiết hóa đơn (Web & In) -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm print:border-0 print:shadow-none print:rounded-none print:mt-4">
        <!-- Title & Subtitle (Web) -->
        <div class="bg-slate-50 border-b border-gray-200 px-5 py-3.5 flex items-center justify-between flex-wrap gap-2 print:hidden">
          <h2 class="font-bold text-slate-800 text-sm uppercase tracking-wide">Chi tiết hóa đơn bán hàng trong kỳ</h2>
          <span class="text-xs text-gray-500 font-medium">
            Có {{ invoices.length }} hóa đơn được duyệt hạch toán
          </span>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm print:text-[9px]">
            <thead class="bg-slate-700 text-white print:bg-slate-800 print:text-[10px]">
              <tr>
                <th class="px-3 py-3 font-semibold text-center w-[5%] print:py-2">STT</th>
                <th class="px-3 py-3 font-semibold text-center w-[15%] print:py-2">Số chứng từ</th>
                <th class="px-4 py-3 font-semibold text-left w-[35%] print:py-2">Khách hàng</th>
                <th class="px-3 py-3 font-semibold text-center w-[12%] print:py-2">Ngày hóa đơn</th>
                <th class="px-4 py-3 font-semibold text-right w-[15%] print:py-2">Doanh thu chưa VAT</th>
                <th class="px-4 py-3 font-semibold text-right w-[12%] print:py-2">Thuế GTGT</th>
                <th class="px-4 py-3 font-semibold text-right w-[15%] print:py-2">Tổng thanh toán</th>
                <th class="px-3 py-3 font-semibold text-center w-[11%] print:hidden">Trạng thái</th>
                <th class="hidden print:table-cell px-3 py-3 font-semibold text-center w-[11%] print:py-2">Trạng thái</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 border-b border-gray-200 print:border-gray-300">
              <tr v-for="(invoice, index) in invoices" :key="invoice.id" class="hover:bg-slate-50/50 print:hover:bg-transparent">
                <td class="px-3 py-3 text-center text-gray-500 print:py-1.5">{{ index + 1 }}</td>
                <td class="px-3 py-3 text-center font-mono font-bold text-blue-700 print:py-1.5 print:text-gray-900">{{ invoice.code }}</td>
                <td class="px-4 py-3 text-gray-800 print:py-1.5 print:text-gray-900">{{ invoice.customer_name || 'Khách lẻ' }}</td>
                <td class="px-3 py-3 text-center text-gray-600 print:py-1.5">{{ fmtDate(invoice.issue_date) }}</td>
                <td class="px-4 py-3 text-right text-gray-700 print:py-1.5">{{ fmt(invoice.subtotal) }}</td>
                <td class="px-4 py-3 text-right text-gray-700 print:py-1.5">{{ fmt(invoice.tax_amount) }}</td>
                <td class="px-4 py-3 text-right font-medium text-slate-800 print:py-1.5 print:text-gray-900">{{ fmt(invoice.total) }}</td>
                <td class="px-3 py-3 text-center print:hidden">
                  <StatusBadge :status="invoice.status">{{ getStatusLabel(invoice.status) }}</StatusBadge>
                </td>
                <td class="hidden print:table-cell px-3 py-3 text-center print:py-1.5">
                  {{ getStatusLabel(invoice.status) }}
                </td>
              </tr>
              <tr v-if="invoices.length === 0">
                <td colspan="8" class="px-4 py-8 text-center text-gray-400 italic">
                  Không tìm thấy hóa đơn bán hàng hợp lệ nào trong kỳ báo cáo này.
                </td>
              </tr>
              <!-- Row tổng cộng trong bảng -->
              <tr class="bg-slate-50/80 font-bold border-t-2 border-slate-300 print:bg-gray-100 print:border-gray-400 print:text-[9.5px]">
                <td class="px-3 py-3 text-center print:py-2"></td>
                <td colspan="3" class="px-4 py-3 text-center uppercase text-slate-800 print:py-2 print:text-gray-900">
                  Tổng cộng ({{ summary.count_invoices }} hóa đơn)
                </td>
                <td class="px-4 py-3 text-right text-slate-900 print:py-2">{{ fmt(summary.total_subtotal) }}</td>
                <td class="px-4 py-3 text-right text-slate-900 print:py-2">{{ fmt(summary.total_tax) }}</td>
                <td class="px-4 py-3 text-right text-slate-900 print:py-2">{{ fmt(summary.total_payment) }}</td>
                <td class="print:hidden"></td>
                <td class="hidden print:table-cell"></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Signature block (In) -->
      <div class="hidden print:block mt-8 print:break-inside-avoid">
        <p class="text-sm italic text-right mb-6">Lập, ngày ..... tháng ..... năm {{ year }}</p>
        <div class="grid grid-cols-3 gap-4 text-center">
          <div>
            <p class="font-bold text-xs uppercase text-gray-900">Người lập biểu</p>
            <p class="text-[10px] text-gray-500 italic">(Ký, họ tên)</p>
            <p class="mt-16 text-xs text-gray-400">&nbsp;</p>
          </div>
          <div>
            <p class="font-bold text-xs uppercase text-gray-900">Kế toán trưởng</p>
            <p class="text-[10px] text-gray-500 italic">(Ký, họ tên)</p>
            <p class="mt-16 text-xs text-gray-400">&nbsp;</p>
          </div>
          <div>
            <p class="font-bold text-xs uppercase text-gray-900">Người đại diện theo pháp luật</p>
            <p class="text-[10px] text-gray-500 italic">(Ký, họ tên, đóng dấu)</p>
            <p class="mt-16 text-xs text-gray-400">&nbsp;</p>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  invoices:       Array,
  summary:        Object,
  gl_reconcile:   Object,
  filters:        Object,
  company:        Object,
  availableYears: Array,
  periodLabel:    String,
});

const { formatVnd: fmt } = useCurrency();

const periodType = ref(props.filters.period_type || 'month');
const year       = ref(props.filters.year || new Date().getFullYear());
const month      = ref(props.filters.month || new Date().getMonth() + 1);
const quarter    = ref(props.filters.quarter || Math.ceil((new Date().getMonth() + 1) / 3));

const exportExcelUrl = computed(() => {
  return route('reports.revenue.export') + 
         `?period_type=${periodType.value}&year=${year.value}&month=${month.value}&quarter=${quarter.value}`;
});

const exportPdfUrl = computed(() => {
  return route('reports.revenue.pdf') + 
         `?period_type=${periodType.value}&year=${year.value}&month=${month.value}&quarter=${quarter.value}`;
});

function applyFilters() {
  router.get(route('reports.revenue'), {
    period_type: periodType.value,
    year:        year.value,
    month:       month.value,
    quarter:     quarter.value,
  }, { preserveState: true, replace: true });
}

function printReport() {
  window.print();
}

function fmtDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('vi-VN');
}

function formatCurrentDate() {
  const d = new Date();
  const date = String(d.getDate()).padStart(2, '0');
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const year = d.getFullYear();
  const hours = String(d.getHours()).padStart(2, '0');
  const minutes = String(d.getMinutes()).padStart(2, '0');
  return `${date}/${month}/${year} ${hours}:${minutes}`;
}

function formatDiff(v) {
  if (v === 0) return 'Khớp';
  const prefix = v > 0 ? '+' : '';
  return prefix + fmt(v);
}

function getStatusLabel(status) {
  return {
    'draft':     'Nháp',
    'sent':      'Đã gửi',
    'paid':      'Đã thanh toán',
    'overdue':   'Quá hạn',
    'cancelled': 'Đã hủy',
  }[status] || status;
}
</script>

<style scoped>
@media print {
  /* Ẩn scrollbar và background mặc định */
  body {
    background: #fff !important;
    color: #000 !important;
  }
}
</style>
