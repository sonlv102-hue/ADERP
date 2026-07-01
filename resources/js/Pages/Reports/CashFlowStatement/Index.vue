<template>
  <AppLayout>
    <div class="space-y-5 max-w-7xl mx-auto">

      <!-- Page header -->
      <div class="flex justify-between items-start flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Báo cáo lưu chuyển tiền tệ</h1>
          <p class="text-sm text-gray-500 mt-0.5">Mẫu số B03-DNN — TT 133/2016/TT-BTC — Phương pháp trực tiếp</p>
        </div>
        <div class="flex gap-2 flex-wrap">
          <a :href="exportExcelUrl" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Xuất Excel
          </a>
          <a :href="exportPdfUrl" target="_blank" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
            Xuất PDF
          </a>
          <button onclick="window.print()" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            In
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4 flex gap-4 flex-wrap items-end">
        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Kỳ báo cáo</label>
          <select v-model="periodType" class="erp-input text-sm w-32">
            <option value="month">Tháng</option>
            <option value="quarter">Quý</option>
            <option value="year">Năm</option>
            <option value="custom">Tùy chọn</option>
          </select>
        </div>

        <div v-if="periodType !== 'custom'" class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Năm</label>
          <select v-model="selectedYear" class="erp-input text-sm w-28">
            <option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>

        <div v-if="periodType === 'month'" class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Tháng</label>
          <select v-model="selectedMonth" class="erp-input text-sm w-20">
            <option v-for="m in 12" :key="m" :value="m">{{ String(m).padStart(2, '0') }}</option>
          </select>
        </div>

        <div v-if="periodType === 'quarter'" class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Quý</label>
          <select v-model="selectedQuarter" class="erp-input text-sm w-24">
            <option :value="1">I</option>
            <option :value="2">II</option>
            <option :value="3">III</option>
            <option :value="4">IV</option>
          </select>
        </div>

        <template v-if="periodType === 'custom'">
          <div class="flex flex-col gap-1">
            <label class="text-xs text-gray-500 font-medium">Từ ngày</label>
            <input type="date" v-model="dateFrom" class="erp-input text-sm">
          </div>
          <div class="flex flex-col gap-1">
            <label class="text-xs text-gray-500 font-medium">Đến ngày</label>
            <input type="date" v-model="dateTo" class="erp-input text-sm">
          </div>
        </template>

        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">So sánh</label>
          <select v-model="compareType" class="erp-input text-sm w-44">
            <option value="none">Không</option>
            <option value="same_period_last_year">Cùng kỳ năm trước</option>
            <option value="previous_period">Kỳ liền trước</option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Đơn vị tính</label>
          <select v-model="selectedUnit" class="erp-input text-sm w-36">
            <option value="dong">Đồng</option>
            <option value="nghin_dong">Nghìn đồng</option>
            <option value="trieu_dong">Triệu đồng</option>
          </select>
        </div>
        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Phương pháp</label>
          <select class="erp-input text-sm w-36" disabled>
            <option>Trực tiếp</option>
          </select>
        </div>
        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Dòng trống</label>
          <label class="flex items-center gap-2 text-sm text-gray-700 mt-1">
            <input type="checkbox" v-model="hideEmpty" class="rounded" />
            Ẩn dòng = 0
          </label>
        </div>
        <button @click="applyFilters" class="erp-btn-primary text-sm px-4 py-2 self-end">
          Xem báo cáo
        </button>
      </div>

      <!-- Reconciliation warning -->
      <div v-if="!report.reconciliation.ok" class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 flex gap-2 items-start text-sm text-red-700">
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
        </svg>
        <span>
          Mã 70 ({{ fmt(report.reconciliation.reported_closing) }}) chưa khớp số dư TK 111/112 cuối kỳ
          ({{ fmt(report.reconciliation.actual_closing) }}).
          Chênh lệch: {{ fmt(report.reconciliation.difference) }}.
          Cần kiểm tra chứng từ chưa phân loại.
        </span>
      </div>

      <!-- Unclassified warning -->
      <div v-if="unclassifiedCount > 0" class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-700 flex items-center justify-between gap-3">
        <span>
          ⚠ Có <strong>{{ unclassifiedCount }}</strong> phiếu thu/chi chưa có mã lưu chuyển tiền tệ.
          Kết quả báo cáo có thể chưa chính xác.
        </span>
        <button @click="showUnclassified = !showUnclassified" class="text-amber-700 underline text-xs whitespace-nowrap">
          {{ showUnclassified ? 'Ẩn' : 'Xem danh sách' }}
        </button>
      </div>

      <!-- Unclassified table -->
      <div v-if="showUnclassified && unclassified.length" class="bg-white rounded-xl border border-amber-200 overflow-x-auto">
        <table class="min-w-full text-xs">
          <thead class="bg-amber-50">
            <tr>
              <th class="px-3 py-2 text-left text-gray-600 font-semibold">Mã phiếu</th>
              <th class="px-3 py-2 text-left text-gray-600 font-semibold">Loại</th>
              <th class="px-3 py-2 text-left text-gray-600 font-semibold">Ngày</th>
              <th class="px-3 py-2 text-left text-gray-600 font-semibold">Đối tác</th>
              <th class="px-3 py-2 text-left text-gray-600 font-semibold">Diễn giải</th>
              <th class="px-3 py-2 text-right text-gray-600 font-semibold">Số tiền</th>
              <th class="px-3 py-2 text-center text-gray-600 font-semibold">Mã LCTT</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="v in unclassified" :key="v.id" class="hover:bg-amber-50">
              <td class="px-3 py-2 font-mono">{{ v.code }}</td>
              <td class="px-3 py-2">{{ v.type === 'receipt' ? 'Phiếu thu' : 'Phiếu chi' }}</td>
              <td class="px-3 py-2">{{ v.voucher_date }}</td>
              <td class="px-3 py-2">{{ v.counterparty }}</td>
              <td class="px-3 py-2">{{ v.description }}</td>
              <td class="px-3 py-2 text-right">{{ fmt(v.amount) }}</td>
              <td class="px-3 py-2 text-center">
                <select class="text-xs border border-gray-300 rounded px-1 py-0.5 w-16"
                  @change="updateVoucherCode(v.id, $event.target.value)">
                  <option value="">—</option>
                  <option v-for="(label, code) in CASH_FLOW_CODES" :key="code" :value="code">{{ code }}</option>
                </select>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- B03-DNN Report -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto print:shadow-none print:border-none" id="b03-print">
        <!-- Report header -->
        <div class="px-6 pt-5 pb-2">
          <div class="flex justify-between items-start">
            <div class="text-sm">
              <p class="font-semibold">{{ company.company_name ?? '' }}</p>
              <p class="text-gray-500 text-xs">Địa chỉ: {{ company.company_address ?? '' }}</p>
            </div>
            <div class="text-right text-xs text-gray-500 italic">
              <p class="font-semibold text-gray-800 not-italic text-sm">Mẫu số B03-DNN</p>
              <p>(Ban hành theo Thông tư số 133/2016/TT-BTC</p>
              <p>ngày 26/8/2016 của Bộ Tài chính)</p>
            </div>
          </div>
          <div class="text-center mt-4 mb-1">
            <h2 class="text-lg font-bold uppercase tracking-wide">Báo cáo lưu chuyển tiền tệ</h2>
            <p class="text-sm italic text-gray-600">(Theo phương pháp trực tiếp)</p>
            <p class="text-sm italic text-gray-600">{{ report.period?.label }}</p>
            <p class="text-xs text-gray-500">Kỳ báo cáo: Từ ngày {{ fmtDate(report.period?.date_from) }} đến ngày {{ fmtDate(report.period?.date_to) }}</p>
          </div>
          <p class="text-right text-xs italic text-gray-500">Đơn vị tính: {{ unitLabel }} · Nguồn số liệu: Bút toán GL đã posted</p>
        </div>

        <!-- Table -->
        <table class="min-w-full border-collapse">
          <thead>
            <tr class="bg-slate-700 text-white">
              <th class="px-3 py-2.5 text-left text-xs font-semibold w-1/2">Chỉ tiêu</th>
              <th class="px-2 py-2.5 text-center text-xs font-semibold w-16">Mã số</th>
              <th class="px-2 py-2.5 text-center text-xs font-semibold w-24">Thuyết minh</th>
              <th class="px-3 py-2.5 text-right text-xs font-semibold w-36">{{ report.period?.label ?? 'Kỳ này' }}</th>
              <th class="px-3 py-2.5 text-right text-xs font-semibold w-36">{{ report.comparison_period?.label ?? 'Kỳ so sánh' }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(row, idx) in visibleRows" :key="row.code + idx">
              <!-- Section header -->
              <tr v-if="row.__sectionHeader" class="bg-blue-50 border-y border-blue-200">
                <td colspan="5" class="px-3 py-2 text-sm font-bold text-blue-900">{{ row.__sectionLabel }}</td>
              </tr>
              <!-- Data row -->
              <tr v-else
                class="border-b border-gray-100 transition-colors"
                :class="{
                  'bg-green-50 font-bold': row.is_summary && !['50','60','61','70'].includes(row.code),
                  'bg-blue-50 font-bold': ['50','70'].includes(row.code),
                  'bg-yellow-50 font-semibold': ['60','61'].includes(row.code),
                  'hover:bg-gray-50': !row.is_summary,
                }">
                <td class="px-3 py-2 text-sm"
                  :class="row.is_summary ? '' : 'pl-8 text-gray-700'">
                  {{ row.label }}
                </td>
                <td class="px-2 py-2 text-center text-sm text-gray-500">{{ row.code }}</td>
                <td class="px-2 py-2 text-center text-xs text-gray-400">{{ row.note ?? '' }}</td>
                <td class="px-3 py-2 text-right text-sm font-mono"
                  :class="row.curr < 0 ? 'text-red-600' : (row.curr > 0 ? 'text-gray-900' : 'text-gray-300')">
                  {{ fmtAmount(row.curr) }}
                </td>
                <td class="px-3 py-2 text-right text-sm font-mono"
                  :class="row.prev < 0 ? 'text-red-600' : (row.prev > 0 ? 'text-gray-900' : 'text-gray-300')">
                  {{ fmtAmount(row.prev) }}
                </td>
              </tr>
            </template>
          </tbody>
        </table>

        <!-- Signature block -->
        <div class="px-6 py-5 border-t border-gray-100">
          <p class="text-right text-sm italic text-gray-500 mb-6">
            Lập, ngày &nbsp;&nbsp;&nbsp; tháng &nbsp;&nbsp;&nbsp; năm {{ report.period?.date_to ? new Date(report.period.date_to).getFullYear() : report.year }}
          </p>
          <div class="grid grid-cols-3 gap-4 text-center">
            <div>
              <p class="text-sm font-semibold uppercase">Người lập biểu</p>
              <p class="text-xs text-gray-400 italic">(Ký, họ tên)</p>
              <div class="h-12"></div>
              <p class="text-xs text-gray-400">(Ghi rõ họ tên)</p>
            </div>
            <div>
              <p class="text-sm font-semibold uppercase">Kế toán trưởng</p>
              <p class="text-xs text-gray-400 italic">(Ký, họ tên)</p>
              <div class="h-12"></div>
              <p class="text-xs text-gray-400">(Ghi rõ họ tên)</p>
            </div>
            <div>
              <p class="text-sm font-semibold uppercase">Người đại diện theo pháp luật</p>
              <p class="text-xs text-gray-400 italic">(Ký, họ tên, đóng dấu)</p>
              <div class="h-12"></div>
              <p class="text-xs text-gray-400">(Ghi rõ họ tên)</p>
            </div>
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
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({
  report:           { type: Object,  required: true },
  unclassifiedCount:{ type: Number,  default: 0 },
  unclassified:     { type: Array,   default: () => [] },
  company:          { type: Object,  default: () => ({}) },
  filters:          { type: Object,  default: () => ({}) },
  availableYears:   { type: Array,   default: () => [] },
});

const { formatVnd: fmt } = useCurrency();

const periodType     = ref(props.filters.period_type ?? 'year');
const selectedYear   = ref(Number(props.filters.year ?? new Date().getFullYear()));
const selectedMonth  = ref(Number(props.filters.month ?? new Date().getMonth() + 1));
const selectedQuarter = ref(Number(props.filters.quarter ?? Math.ceil((new Date().getMonth() + 1) / 3)));
const dateFrom       = ref(props.filters.date_from ?? props.report.period?.date_from ?? '');
const dateTo         = ref(props.filters.date_to ?? props.report.period?.date_to ?? '');
const compareType    = ref(props.filters.compare_type ?? 'same_period_last_year');
const selectedUnit   = ref(props.filters.unit ?? 'dong');
const hideEmpty      = ref(false);
const showUnclassified = ref(false);

function fmtDate(d) {
  if (!d) return '';
  const [y, m, day] = String(d).split('-');
  return `${day}/${m}/${y}`;
}

const CASH_FLOW_CODES = {
  '01': 'Thu bán hàng',   '02': 'Chi trả NCC',       '03': 'Chi trả lương',
  '04': 'Chi lãi vay',    '05': 'Nộp thuế TNDN',     '06': 'Thu khác HĐKD',
  '07': 'Chi khác HĐKD', '21': 'Chi mua TSCĐ',       '22': 'Thu thanh lý TSCĐ',
  '23': 'Chi cho vay',    '24': 'Thu hồi cho vay',    '25': 'Thu lãi/cổ tức',
  '31': 'Thu vốn góp',    '32': 'Trả vốn góp',        '33': 'Thu vay',
  '34': 'Trả nợ vay',     '35': 'Trả cổ tức',
};

const unitLabel = computed(() => ({
  nghin_dong: 'Nghìn đồng', trieu_dong: 'Triệu đồng', dong: 'Đồng',
}[selectedUnit.value] ?? 'Đồng'));

// Build rows with section headers injected
const visibleRows = computed(() => {
  const result = [];
  let currentSection = null;
  const SECTION_LABELS = {
    'I':   'I. Lưu chuyển tiền từ hoạt động kinh doanh',
    'II':  'II. Lưu chuyển tiền từ hoạt động đầu tư',
    'III': 'III. Lưu chuyển tiền từ hoạt động tài chính',
  };

  for (const row of (props.report.rows ?? [])) {
    if (hideEmpty.value && !row.is_summary && row.curr === 0 && row.prev === 0) continue;

    if (row.section && row.section !== currentSection) {
      result.push({ __sectionHeader: true, __sectionLabel: SECTION_LABELS[row.section] ?? row.section });
      currentSection = row.section;
    }
    result.push(row);
  }
  return result;
});

function periodParams() {
  const params = { period_type: periodType.value, unit: selectedUnit.value, compare_type: compareType.value };
  if (periodType.value === 'custom') {
    params.date_from = dateFrom.value;
    params.date_to = dateTo.value;
  } else {
    params.year = selectedYear.value;
    if (periodType.value === 'month') params.month = selectedMonth.value;
    if (periodType.value === 'quarter') params.quarter = selectedQuarter.value;
  }
  return params;
}

const exportExcelUrl = computed(() => {
  const p = new URLSearchParams(periodParams());
  return route('reports.cash_flow_statement.export') + '?' + p.toString();
});

const exportPdfUrl = computed(() => {
  const p = new URLSearchParams(periodParams());
  return route('reports.cash_flow_statement.pdf') + '?' + p.toString();
});

function applyFilters() {
  router.get(route('reports.cash_flow_statement'), periodParams(), { preserveState: false });
}

function fmtAmount(val) {
  if (val === null || val === undefined || val === 0) return '—';
  if (val < 0) return '(' + new Intl.NumberFormat('vi-VN').format(Math.abs(val)) + ')';
  return new Intl.NumberFormat('vi-VN').format(val);
}

function updateVoucherCode(voucherId, code) {
  router.patch(route('reports.cash_flow_statement.update_code'), {
    voucher_id: voucherId,
    cash_flow_code: code || null,
  }, { preserveScroll: true });
}
</script>
