<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Page header -->
      <div class="erp-page-header">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Báo cáo kết quả hoạt động kinh doanh</h1>
          <p class="text-sm text-gray-500 mt-0.5">Mẫu số B02-DNN — Thông tư 133/2016/TT-BTC · Nguồn: bút toán GL đã posted</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <a :href="exportExcelUrl" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Excel
          </a>
          <a :href="exportPdfUrl" target="_blank" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            PDF
          </a>
          <button onclick="window.print()" class="erp-btn-secondary flex items-center gap-1.5 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            In
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex items-center gap-3 flex-wrap bg-white rounded-xl border border-gray-200 px-4 py-3">
        <div class="flex items-center gap-2">
          <label class="text-sm font-medium text-gray-600">Năm:</label>
          <select v-model="year" @change="applyFilters" class="erp-input text-sm w-24">
            <option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm font-medium text-gray-600">Đơn vị tính:</label>
          <select v-model="unit" @change="applyFilters" class="erp-input text-sm w-36">
            <option value="dong">Đồng</option>
            <option value="nghin_dong">Nghìn đồng</option>
            <option value="trieu_dong">Triệu đồng</option>
          </select>
        </div>
        <div class="flex items-center gap-2 ml-2">
          <input id="hideEmpty" v-model="hideEmpty" type="checkbox" class="rounded border-gray-300 text-primary-600">
          <label for="hideEmpty" class="text-sm text-gray-600">Ẩn dòng không có số liệu</label>
        </div>
      </div>

      <!-- Warnings -->
      <div v-if="report.warnings?.length" class="space-y-2">
        <div v-for="(w, i) in report.warnings" :key="i"
          class="rounded-lg px-4 py-3 border text-sm"
          :class="{
            'bg-red-50 border-red-300 text-red-800':       w.level === 'error',
            'bg-yellow-50 border-yellow-300 text-yellow-800': w.level === 'warning',
            'bg-blue-50 border-blue-200 text-blue-800':     w.level === 'info',
          }">
          {{ w.message }}
        </div>
      </div>

      <!-- B02-DNN Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto print:border-0 print:rounded-none print:shadow-none">
        <!-- Report header (print) -->
        <div class="hidden print:block px-6 py-4">
          <div class="flex justify-between items-start mb-4">
            <div class="text-sm">
              <div class="font-bold">{{ company?.company_name }}</div>
              <div>Địa chỉ: {{ company?.company_address }}</div>
            </div>
            <div class="text-right text-sm italic">
              <div class="font-bold not-italic">Mẫu số B02-DNN</div>
              <div>(Ban hành theo Thông tư số 133/2016/TT-BTC</div>
              <div>ngày 26/8/2016 của Bộ Tài chính)</div>
            </div>
          </div>
          <h2 class="text-center text-xl font-bold uppercase tracking-wide mb-1">Báo cáo kết quả hoạt động kinh doanh</h2>
          <p class="text-center text-sm italic mb-1">Năm {{ report.year }}</p>
          <p class="text-right text-xs italic">Đơn vị tính: {{ unitLabel }}</p>
        </div>

        <!-- Screen header -->
        <div class="print:hidden bg-gray-50 border-b border-gray-200 px-5 py-3 flex items-center justify-between">
          <h2 class="font-semibold text-gray-800">Báo cáo KQHĐKD — Năm {{ report.year }}</h2>
          <span class="text-xs text-gray-400">Đơn vị: {{ unitLabel }}</span>
        </div>

        <table class="min-w-full text-sm">
          <thead>
            <tr class="bg-slate-700 text-white">
              <th class="text-left px-4 py-2.5 font-medium text-xs w-[42%]">CHỈ TIÊU</th>
              <th class="text-center px-2 py-2.5 font-medium text-xs w-[7%]">Mã số</th>
              <th class="text-center px-2 py-2.5 font-medium text-xs w-[9%]">Thuyết minh</th>
              <th class="text-right px-4 py-2.5 font-medium text-xs w-[21%]">Năm nay</th>
              <th class="text-right px-4 py-2.5 font-medium text-xs w-[21%]">Năm trước</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <template v-for="row in visibleRows" :key="row.code">
              <tr :class="[row.isSummary ? 'bg-green-50 font-semibold' : 'hover:bg-gray-50']">
                <td class="px-4 py-2 text-gray-800"
                  :class="row.code === '23' ? 'pl-8 text-gray-500 italic' : ''">
                  <span>{{ row.label }}</span>
                </td>
                <td class="px-2 py-2 text-center text-xs text-gray-500 font-mono">
                  <button v-if="hasDetail(row.code)"
                    @click="openDetail(row.code)"
                    class="text-primary-600 hover:underline font-mono">
                    {{ row.code }}
                  </button>
                  <span v-else>{{ row.code }}</span>
                </td>
                <td class="px-2 py-2 text-center text-xs text-gray-400">{{ row.note ?? '' }}</td>
                <td class="px-4 py-2 text-right"
                  :class="[
                    row.isSummary ? 'font-bold' : '',
                    amtClass(row.code, row.curr),
                  ]">
                  {{ fmtAmount(row.code, row.curr) }}
                </td>
                <td class="px-4 py-2 text-right text-gray-400"
                  :class="row.isSummary ? 'font-bold' : ''">
                  {{ fmtAmount(row.code, row.prev) }}
                </td>
              </tr>
            </template>
          </tbody>
        </table>

        <!-- Signature block -->
        <div class="px-6 py-6 border-t border-gray-200 print:mt-8">
          <p class="text-sm italic text-right mb-6">Lập, ngày &nbsp;&nbsp;&nbsp; tháng &nbsp;&nbsp;&nbsp; năm {{ report.year }}</p>
          <div class="grid grid-cols-3 gap-4 text-center">
            <div>
              <p class="font-semibold text-xs uppercase">Người lập biểu</p>
              <p class="text-xs text-gray-500 italic">(Ký, họ tên)</p>
              <p class="mt-16 text-sm">&nbsp;</p>
            </div>
            <div>
              <p class="font-semibold text-xs uppercase">Kế toán trưởng</p>
              <p class="text-xs text-gray-500 italic">(Ký, họ tên)</p>
              <p class="mt-16 text-sm">&nbsp;</p>
            </div>
            <div>
              <p class="font-semibold text-xs uppercase">Người đại diện theo pháp luật</p>
              <p class="text-xs text-gray-500 italic">(Ký, họ tên, đóng dấu)</p>
              <p class="mt-16 text-sm">&nbsp;</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Line detail modal -->
    <div v-if="detailModal.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-5xl max-h-[80vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 flex-shrink-0">
          <h3 class="font-semibold text-gray-800">
            Chi tiết mã {{ detailModal.code }} — {{ codeLabel(detailModal.code) }} — Năm {{ report.year }}
          </h3>
          <button @click="detailModal.open = false" class="text-gray-400 hover:text-gray-600">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
        <div class="overflow-y-auto flex-1">
          <div v-if="detailModal.loading" class="py-12 text-center text-gray-400 text-sm">Đang tải...</div>
          <div v-else-if="!detailModal.entries?.length" class="py-12 text-center text-gray-400 text-sm">Không có dữ liệu</div>
          <table v-else class="min-w-full text-xs">
            <thead class="bg-gray-50 sticky top-0">
              <tr>
                <th class="text-left px-3 py-2 font-medium text-gray-600">Ngày HT</th>
                <th class="text-left px-3 py-2 font-medium text-gray-600">Số BT</th>
                <th class="text-left px-3 py-2 font-medium text-gray-600 max-w-xs">Diễn giải</th>
                <th class="text-center px-3 py-2 font-medium text-gray-600">TK</th>
                <th class="text-right px-3 py-2 font-medium text-gray-600">Số tiền</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(e, i) in detailModal.entries" :key="i" class="hover:bg-gray-50">
                <td class="px-3 py-1.5 text-gray-500">{{ e.date }}</td>
                <td class="px-3 py-1.5 font-mono">
                  <a :href="route('accounting.journal-entries.show', e.je_id)" target="_blank"
                    class="text-primary-600 hover:underline">{{ e.je_code }}</a>
                </td>
                <td class="px-3 py-1.5 text-gray-600 max-w-xs truncate">{{ e.description }}</td>
                <td class="px-3 py-1.5 text-center font-mono text-gray-500">{{ e.account_code }}</td>
                <td class="px-3 py-1.5 text-right font-medium">{{ fmt(e.amount) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 border-t-2 border-gray-300">
              <tr>
                <td colspan="4" class="px-3 py-2 font-semibold text-gray-700 text-xs">Tổng cộng</td>
                <td class="px-3 py-2 text-right font-bold text-gray-900">
                  {{ fmt(detailModal.entries.reduce((s, e) => s + e.amount, 0)) }}
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
  report:         Object,
  company:        Object,
  filters:        Object,
  availableYears: Array,
});

const { formatVnd: fmt } = useCurrency();

const year      = ref(props.filters?.year ?? props.report?.year ?? new Date().getFullYear());
const unit      = ref(props.filters?.unit ?? 'dong');
const hideEmpty = ref(false);

const detailModal = ref({ open: false, code: '', loading: false, entries: [] });

const unitLabel = computed(() => ({
  nghin_dong:  'Nghìn đồng',
  trieu_dong:  'Triệu đồng',
})[unit.value] ?? 'Đồng');

const visibleRows = computed(() => {
  if (!hideEmpty.value) return props.report?.rows ?? [];
  return (props.report?.rows ?? []).filter(r => r.curr !== 0 || r.prev !== 0 || r.isSummary);
});

// Codes where value is displayed as negative (cost side) — shown in parentheses when positive
const COST_CODES = ['02', '11', '22', '23', '24', '32', '51'];

function fmtAmount(code, val) {
  if (val === 0 || val === null || val === undefined) return '—';
  if (COST_CODES.includes(code)) {
    return val !== 0 ? '(' + fmt(Math.abs(val)) + ')' : '—';
  }
  return val < 0 ? '(' + fmt(Math.abs(val)) + ')' : fmt(val);
}

function amtClass(code, val) {
  if (val === 0) return 'text-gray-300';
  if (COST_CODES.includes(code)) return 'text-red-700';
  return val > 0 ? 'text-gray-900' : 'text-red-700';
}

// Codes that have GL detail entries
const DETAIL_CODES = ['01', '02', '11', '21', '22', '24', '31', '32', '51'];
function hasDetail(code) { return DETAIL_CODES.includes(code); }

function codeLabel(code) {
  const row = (props.report?.rows ?? []).find(r => r.code === code);
  return row?.label ?? '';
}

async function openDetail(code) {
  detailModal.value = { open: true, code, loading: true, entries: [] };
  try {
    const res = await fetch(
      route('reports.income_statement.line_detail') + `?code=${code}&year=${props.report.year}`
    );
    const data = await res.json();
    detailModal.value.entries = data.entries ?? [];
  } catch {
    detailModal.value.entries = [];
  } finally {
    detailModal.value.loading = false;
  }
}

const exportExcelUrl = computed(() => {
  const p = new URLSearchParams({ year: year.value, unit: unit.value });
  return route('reports.income_statement.export') + '?' + p.toString();
});

const exportPdfUrl = computed(() => {
  const p = new URLSearchParams({ year: year.value, unit: unit.value });
  return route('reports.income_statement.pdf') + '?' + p.toString();
});

function applyFilters() {
  router.get(route('reports.income_statement'), { year: year.value, unit: unit.value }, {
    preserveState: true, replace: true,
  });
}
</script>
