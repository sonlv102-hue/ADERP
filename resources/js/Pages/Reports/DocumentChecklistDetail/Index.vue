<template>
  <AppLayout>
    <div class="space-y-4">

      <!-- Page header -->
      <div class="erp-page-header">
        <div>
          <h1 class="text-xl font-bold text-gray-900">Bảng kê chứng từ chi tiết</h1>
          <p class="text-sm text-gray-500 mt-0.5">Liệt kê chi tiết từng dòng bút toán đã ghi sổ, kèm tài khoản đối ứng</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <a :href="exportExcelUrl" class="erp-btn-secondary text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Excel
          </a>
          <a :href="exportPdfUrl" class="erp-btn-secondary text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
            PDF
          </a>
          <button @click="print()" class="erp-btn-secondary text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            In
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <div class="space-y-1">
            <label class="text-xs font-medium text-gray-600">Từ ngày</label>
            <input v-model="f.date_from" type="date" class="erp-input w-full" />
          </div>
          <div class="space-y-1">
            <label class="text-xs font-medium text-gray-600">Đến ngày</label>
            <input v-model="f.date_to" type="date" class="erp-input w-full" />
          </div>
          <div class="space-y-1">
            <label class="text-xs font-medium text-gray-600">Số chứng từ</label>
            <input v-model="f.ref_no" type="text" placeholder="Tìm theo mã bút toán..." class="erp-input w-full" />
          </div>
          <div class="space-y-1">
            <label class="text-xs font-medium text-gray-600">Loại chứng từ</label>
            <select v-model="f.source_type" class="erp-input w-full">
              <option value="">-- Tất cả --</option>
              <option value="invoice">Hóa đơn bán hàng</option>
              <option value="purchase_invoice">Hóa đơn đầu vào</option>
              <option value="cash_voucher">Phiếu thu/chi</option>
              <option value="stock_entry">Nhập kho</option>
              <option value="stock_exit">Xuất kho</option>
              <option value="payroll">Lương</option>
              <option value="project_expense">Chi phí dự án</option>
              <option value="manual">Bút toán thủ công</option>
            </select>
          </div>
          <div class="space-y-1">
            <label class="text-xs font-medium text-gray-600">Tài khoản</label>
            <input v-model="f.account_code" type="text" placeholder="VD: 1111, 1121, 3311" class="erp-input w-full" />
          </div>
          <div class="space-y-1">
            <label class="text-xs font-medium text-gray-600">TK đối ứng</label>
            <input v-model="f.counter_account" type="text" placeholder="VD: 5111, 3311..." class="erp-input w-full" />
          </div>
          <div class="space-y-1">
            <label class="text-xs font-medium text-gray-600">Loại đối tượng</label>
            <select v-model="f.object_type" class="erp-input w-full">
              <option value="">-- Tất cả --</option>
              <option value="customer">Khách hàng</option>
              <option value="supplier">Nhà cung cấp</option>
              <option value="employee">Nhân viên</option>
              <option value="project">Dự án</option>
              <option value="other">Khác</option>
            </select>
          </div>
          <div class="space-y-1 flex items-end gap-3">
            <label class="flex items-center gap-2 cursor-pointer mb-1">
              <input v-model="f.include_reversed" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary-600" />
              <span class="text-xs text-gray-600">Kể cả đảo</span>
            </label>
            <button @click="applyFilters" class="erp-btn-primary text-sm flex-1">Xem báo cáo</button>
          </div>
        </div>
      </div>

      <!-- Balance warning -->
      <div v-if="!isBalanced" class="flex items-center gap-3 px-4 py-3 bg-orange-50 border border-orange-200 rounded-xl text-orange-800 text-sm font-medium">
        <svg class="w-5 h-5 flex-shrink-0 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        Báo cáo đang lệch Nợ/Có, vui lòng kiểm tra dữ liệu hạch toán.
      </div>

      <!-- KPI strip -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
          <p class="text-xs text-gray-500 mb-1">Tổng dòng bút toán</p>
          <p class="text-lg font-bold text-gray-900">{{ total.toLocaleString('vi') }}</p>
        </div>
        <div class="bg-blue-50 rounded-xl border border-blue-200 p-4 text-center">
          <p class="text-xs text-blue-600 mb-1">Tổng phát sinh Nợ</p>
          <p class="text-lg font-bold text-blue-800">{{ fmt(totals.debit) }}</p>
        </div>
        <div class="bg-green-50 rounded-xl border border-green-200 p-4 text-center">
          <p class="text-xs text-green-600 mb-1">Tổng phát sinh Có</p>
          <p class="text-lg font-bold text-green-800">{{ fmt(totals.credit) }}</p>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-[#1E3A5F] text-white">
            <tr>
              <th class="px-3 py-2 text-left text-xs font-semibold">Ngày CT</th>
              <th class="px-3 py-2 text-left text-xs font-semibold">Số CT</th>
              <th class="px-3 py-2 text-left text-xs font-semibold">Tên khách / Đối tượng</th>
              <th class="px-3 py-2 text-left text-xs font-semibold">Diễn giải</th>
              <th class="px-3 py-2 text-center text-xs font-semibold">TK</th>
              <th class="px-3 py-2 text-left text-xs font-semibold">Tên tài khoản</th>
              <th class="px-3 py-2 text-center text-xs font-semibold">TK đối ứng</th>
              <th class="px-3 py-2 text-right text-xs font-semibold text-blue-200">Nợ</th>
              <th class="px-3 py-2 text-right text-xs font-semibold text-green-200">Có</th>
              <th class="px-2 py-2 text-center text-xs font-semibold">Nguồn</th>
              <th class="px-3 py-2 text-left text-xs font-semibold">Dự án</th>
              <th class="px-3 py-2 text-left text-xs font-semibold">Trạng thái</th>
              <th class="px-3 py-2 text-left text-xs font-semibold">Người tạo</th>
              <th class="px-3 py-2 text-left text-xs font-semibold">TG hạch toán</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(row, idx) in rows" :key="idx"
              class="hover:bg-gray-50 transition-colors"
              :class="idx % 2 === 1 ? 'bg-gray-50/50' : ''">
              <td class="px-3 py-2 text-xs text-gray-600 whitespace-nowrap">{{ formatDate(row.date) }}</td>
              <td class="px-3 py-2 text-xs font-mono whitespace-nowrap">
                <Link :href="route('accounting.journal-entries.show', row.journal_entry_id)"
                  class="text-primary-600 hover:underline">{{ row.je_code }}</Link>
              </td>
              <td class="px-3 py-2 text-xs text-gray-800 max-w-40 truncate" :title="row.object_name">
                {{ row.object_name || '—' }}
              </td>
              <td class="px-3 py-2 text-xs text-gray-700 max-w-64">{{ row.description }}</td>
              <td class="px-3 py-2 text-xs text-center font-mono font-semibold text-gray-800 whitespace-nowrap">
                {{ row.account_code }}
              </td>
              <td class="px-3 py-2 text-xs text-gray-700">{{ row.account_name }}</td>
              <td class="px-3 py-2 text-xs text-center font-mono whitespace-nowrap"
                :class="row.counter_account === 'Nhiều TK' ? 'text-orange-600 italic' : 'text-gray-700'">
                {{ row.counter_account }}
              </td>
              <td class="px-3 py-2 text-xs text-right font-medium text-blue-700 whitespace-nowrap">
                {{ row.debit > 0 ? fmt(row.debit) : '' }}
              </td>
              <td class="px-3 py-2 text-xs text-right font-medium text-green-700 whitespace-nowrap">
                {{ row.credit > 0 ? fmt(row.credit) : '' }}
              </td>
              <td class="px-2 py-2 text-center">
                <a v-if="row.source_url" :href="row.source_url"
                  class="text-xs text-primary-600 hover:underline" :title="row.source_label">
                  {{ sourceShort(row.source_label) }}
                </a>
                <span v-else class="text-xs text-gray-400">{{ sourceShort(row.source_label) }}</span>
              </td>
              <td class="px-3 py-2 text-xs text-gray-600">{{ row.project_name }}</td>
              <td class="px-3 py-2 text-xs"><StatusBadge :status="row.status">{{ statusLabel(row.status) }}</StatusBadge></td>
              <td class="px-3 py-2 text-xs text-gray-600 whitespace-nowrap">{{ row.created_by_name }}</td>
              <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap">{{ row.posted_at }}</td>
            </tr>

            <!-- Empty -->
            <tr v-if="rows.length === 0">
              <td colspan="14" class="px-4 py-10 text-center text-gray-400 text-sm">
                Không có dữ liệu — hãy chọn khoảng thời gian và bấm "Xem báo cáo"
              </td>
            </tr>

            <!-- Total row -->
            <tr v-if="rows.length > 0" class="bg-blue-50 font-bold border-t-2 border-blue-300">
              <td colspan="7" class="px-3 py-2 text-xs text-center text-gray-700">TỔNG CỘNG</td>
              <td class="px-3 py-2 text-xs text-right text-blue-800">{{ fmt(totals.debit) }}</td>
              <td class="px-3 py-2 text-xs text-right text-green-800">{{ fmt(totals.credit) }}</td>
              <td colspan="5"></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="lastPage > 1" class="flex items-center justify-center gap-2">
        <button v-for="p in pageRange" :key="p" @click="goPage(p)"
          class="px-3 py-1 rounded text-sm"
          :class="p === currentPage
            ? 'bg-primary-600 text-white'
            : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'">
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
  rows:        { type: Array, default: () => [] },
  totals:      { type: Object, default: () => ({ debit: 0, credit: 0 }) },
  isBalanced:  { type: Boolean, default: true },
  total:       { type: Number, default: 0 },
  currentPage: { type: Number, default: 1 },
  lastPage:    { type: Number, default: 1 },
  filters:     { type: Object, default: () => ({}) },
  sourceTypes: { type: Array, default: () => [] },
});

const { formatVnd: fmt } = useCurrency();

const f = ref({
  date_from:        props.filters.date_from        ?? new Date(new Date().getFullYear(), 0, 1).toISOString().slice(0, 10),
  date_to:          props.filters.date_to          ?? new Date().toISOString().slice(0, 10),
  ref_no:           props.filters.ref_no           ?? '',
  source_type:      props.filters.source_type      ?? '',
  account_code:     props.filters.account_code     ?? '',
  counter_account:  props.filters.counter_account  ?? '',
  object_type:      props.filters.object_type      ?? '',
  include_reversed: props.filters.include_reversed ?? false,
});

const pageRange = computed(() => {
  const pages = [];
  for (let i = Math.max(1, props.currentPage - 2); i <= Math.min(props.lastPage, props.currentPage + 2); i++) {
    pages.push(i);
  }
  return pages;
});

const exportExcelUrl = computed(() => route('reports.document_checklist_detail.export') + '?' + buildQuery());
const exportPdfUrl   = computed(() => route('reports.document_checklist_detail.pdf')    + '?' + buildQuery());

function buildQuery() {
  const p = new URLSearchParams();
  Object.entries(f.value).forEach(([k, v]) => { if (v) p.set(k, v); });
  return p.toString();
}

function applyFilters() {
  router.get(route('reports.document_checklist_detail'), { ...f.value, page: 1 },
    { preserveState: true, replace: true });
}

function goPage(p) {
  router.get(route('reports.document_checklist_detail'), { ...f.value, page: p },
    { preserveState: true, replace: true });
}

function formatDate(d) {
  if (!d) return '';
  const dt = new Date(d);
  return dt.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function statusLabel(status) {
  return { draft: 'Nháp', posted: 'Đã hạch toán', reversed: 'Đã đảo', voided: 'Đã hủy' }[status] ?? status;
}

const SOURCE_SHORT = {
  'Hóa đơn bán hàng': 'HĐ bán',
  'Hóa đơn đầu vào':  'HĐ mua',
  'Phiếu thu/chi':    'PT/PC',
  'Nhập kho':         'NK',
  'Xuất kho':         'XK',
  'Lương':            'Lương',
  'Chi phí dự án':    'CPDA',
  'Số dư đầu kỳ':     'ĐK',
  'Bút toán thủ công':'BT',
};

function sourceShort(label) {
  return SOURCE_SHORT[label] ?? label ?? '';
}

function print() {
  window.open(exportPdfUrl.value, '_blank');
}
</script>
