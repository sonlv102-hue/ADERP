<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Kết quả Hoạt động Kinh doanh</h1>
          <p class="text-sm text-gray-500 mt-0.5">Nguồn: bút toán kế toán đã posted (GL) — theo TT133 B02-DNN</p>
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
          <select v-model="year" @change="onYearChange"
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
        <button @click="applyFilters" :disabled="isLoading"
          class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-70 disabled:cursor-not-allowed">
          <svg v-if="isLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Cập nhật
        </button>
      </div>

      <!-- Warnings -->
      <div v-if="warnings?.length" class="space-y-2">
        <div v-for="(w, i) in warnings" :key="i"
          class="rounded-lg px-4 py-3 border"
          :class="{
            'bg-red-50 border-red-300':       w.level === 'error',
            'bg-yellow-50 border-yellow-300': w.level === 'warning',
            'bg-blue-50 border-blue-200':     w.level === 'info',
          }">
          <div class="flex items-start gap-2">
            <svg class="w-4 h-4 flex-shrink-0 mt-0.5"
              :class="{ 'text-red-500': w.level === 'error', 'text-yellow-600': w.level === 'warning', 'text-blue-500': w.level === 'info' }"
              fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-if="w.level !== 'info'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 110 20A10 10 0 0112 2z" />
            </svg>
            <div class="flex-1">
              <p class="text-sm"
                :class="{ 'text-red-800': w.level === 'error', 'text-yellow-800': w.level === 'warning', 'text-blue-800': w.level === 'info' }">
                {{ w.message }}
              </p>
              <!-- Draft JEs list -->
              <div v-if="w.draft_jes?.length" class="mt-2">
                <p class="text-xs font-semibold text-yellow-700 mb-1">Bút toán draft trong kỳ ({{ w.draft_count }} tổng — hiện {{ w.draft_jes.length }} dòng đầu):</p>
                <table class="text-xs w-full border border-yellow-200 rounded">
                  <thead class="bg-yellow-100">
                    <tr>
                      <th class="text-left px-2 py-1">Mã BT</th>
                      <th class="text-left px-2 py-1">Ngày</th>
                      <th class="text-left px-2 py-1">Nội dung</th>
                      <th class="text-left px-2 py-1">Nguồn</th>
                      <th class="px-2 py-1"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="je in w.draft_jes" :key="je.id" class="border-t border-yellow-100">
                      <td class="px-2 py-1 font-mono">{{ je.code }}</td>
                      <td class="px-2 py-1">{{ je.entry_date }}</td>
                      <td class="px-2 py-1 truncate max-w-xs">{{ je.description }}</td>
                      <td class="px-2 py-1 text-gray-500">{{ je.reference_type ?? '—' }}</td>
                      <td class="px-2 py-1">
                        <a :href="route('accounting.journal-entries.show', je.id)"
                          class="text-primary-600 hover:underline" target="_blank">Xem</a>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- KPI cards -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 transition-opacity" :class="{ 'opacity-60': isLoading }">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Doanh thu (TK 511)</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(summary.revenue) }}</p>
          <div class="flex gap-2 mt-1">
            <a :href="ledgerUrl('511')" class="text-xs text-primary-600 hover:underline">Xem chi tiết</a>
          </div>
        </div>
        <div class="bg-white rounded-xl border border-red-200 bg-red-50 p-4">
          <p class="text-xs text-red-600 mb-1">Giá vốn (TK 632)</p>
          <p class="text-lg font-bold text-red-700">{{ fmt(summary.total_cogs) }}</p>
          <a :href="ledgerUrl('632')" class="text-xs text-red-500 hover:underline mt-1 block">Xem chi tiết</a>
        </div>
        <div class="bg-white rounded-xl border p-4" :class="summary.gross_profit >= 0 ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'">
          <p class="text-xs mb-1" :class="summary.gross_profit >= 0 ? 'text-green-600' : 'text-red-600'">Lợi nhuận gộp</p>
          <p class="text-lg font-bold" :class="summary.gross_profit >= 0 ? 'text-green-700' : 'text-red-700'">{{ fmt(summary.gross_profit) }}</p>
          <p class="text-xs mt-0.5" :class="summary.gross_profit >= 0 ? 'text-green-500' : 'text-red-500'">
            {{ summary.gross_margin !== null ? summary.gross_margin + '%' : '—' }}
          </p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Chi phí QLDN (TK 642) <span class="text-gray-400">(quản trị)</span></p>
          <p class="text-lg font-bold text-gray-700">{{ fmt(summary.total_mgmt_expense) }}</p>
          <a :href="ledgerUrl('642')" class="text-xs text-gray-400 hover:underline mt-1 block">Xem chi tiết TK 642</a>
        </div>
      </div>

      <!-- Hướng dẫn đọc báo cáo (TT133 đúng chuẩn) -->
      <div class="bg-blue-50 border border-blue-200 rounded-lg px-5 py-3 text-sm text-blue-800 space-y-1">
        <p class="font-semibold">Hướng dẫn đọc Báo cáo KQHĐKD (TT133 B02-DNN):</p>
        <ul class="list-disc list-inside space-y-0.5 text-blue-700 text-xs">
          <li><strong>Nguồn dữ liệu:</strong> Tất cả chỉ tiêu lấy từ bút toán GL đã posted — không lấy từ hóa đơn hoặc bảng riêng.</li>
          <li><strong>Doanh thu (Mã 01):</strong> Phát sinh Có TK 511 trong kỳ (5111: thương mại · 5113: dịch vụ/dự án). VAT đầu ra 3331 không được cộng vào doanh thu.</li>
          <li><strong>Giảm trừ doanh thu (Mã 02):</strong> Phát sinh Nợ TK 511 theo nghiệp vụ chiết khấu, giảm giá, hàng trả lại. TK 521 là tùy chọn nếu doanh nghiệp cấu hình — không phải mặc định TT133.</li>
          <li><strong>Giá vốn (Mã 11 — TK 632):</strong> Thương mại: Nợ 632/Có 156 khi xuất bán. Dự án: chỉ hạch toán sau khi nghiệm thu (Nợ 632/Có 154) — chi phí 154 chưa nghiệm thu không được tính vào đây.</li>
          <li><strong>Chi phí QLDN (Mã 24 — TK 642):</strong> Tổng TK 642 (bao gồm 6421 bán hàng + 6422 QLDN) — chỉ trừ một lần trong công thức.</li>
          <li><strong>Lợi nhuận sau thuế (Mã 60):</strong> = Lợi nhuận trước thuế − Thuế TNDN (TK 821). Đây là con số cuối cùng phản ánh lợi nhuận thực trong kỳ.</li>
        </ul>
      </div>

      <!-- Cảnh báo lỗ -->
      <div v-if="summary.net_profit !== undefined && summary.net_profit < 0"
        class="bg-red-50 border border-red-300 rounded-lg px-5 py-3">
        <div class="flex items-start gap-3">
          <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
          </svg>
          <div class="flex-1">
            <p class="font-semibold text-red-800 text-sm">Cảnh báo: Kinh doanh lỗ trong kỳ này</p>
            <p class="text-red-700 text-xs mt-0.5">Lợi nhuận sau thuế: {{ fmt(summary.net_profit) }}</p>
            <!-- Drill-down theo TK -->
            <div v-if="drillDown?.by_account?.length" class="mt-2">
              <p class="text-xs font-semibold text-red-700 mb-1">Phân tích theo tài khoản:</p>
              <div class="flex flex-wrap gap-2">
                <span v-for="tk in drillDown.by_account" :key="tk.tk"
                  class="inline-flex items-center gap-1 bg-red-100 border border-red-200 rounded px-2 py-0.5 text-xs text-red-800">
                  <a :href="ledgerUrl(tk.tk)" class="font-mono font-semibold hover:underline">TK {{ tk.tk }}</a>
                  <span class="text-red-600">{{ tk.label }}:</span>
                  <span class="font-semibold">{{ fmt(tk.amount) }}</span>
                </span>
              </div>
            </div>
            <div v-if="drillDown?.unposted_invoices > 0" class="mt-1">
              <p class="text-xs text-red-600">
                Lưu ý: có {{ drillDown.unposted_invoices }} hóa đơn bán hàng chưa có bút toán GL posted — doanh thu thực có thể cao hơn.
                <a :href="route('sales.invoices.index')" class="underline">Xem hóa đơn</a>
              </p>
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 transition-opacity" :class="{ 'opacity-60': isLoading }">
        <!-- P&L Statement -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          <div class="bg-gray-50 border-b border-gray-200 px-5 py-3 flex items-center justify-between">
            <h2 class="font-semibold text-gray-800">Báo cáo KQHĐKD — {{ periodLabel }}</h2>
            <span class="text-xs text-gray-400">Nguồn: GL đã posted</span>
          </div>
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
              <tr>
                <th class="text-left px-5 py-2 text-xs text-gray-500 font-medium">Chỉ tiêu</th>
                <th class="text-center px-2 py-2 text-xs text-gray-400 font-medium w-10">Mã</th>
                <th class="text-right px-5 py-2 text-xs text-gray-500 font-medium">Số tiền</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(line, i) in statement" :key="i"
                class="border-b border-gray-100 last:border-0"
                :class="line.bold ? 'bg-gray-50' : 'hover:bg-gray-50'">
                <td class="px-5 py-2 text-gray-700"
                  :style="{ paddingLeft: line.indent === 2 ? '2.5rem' : line.indent === 1 ? '1.75rem' : '1.25rem' }">
                  <span :class="line.bold ? 'font-semibold text-gray-900' : 'text-sm'">{{ line.label }}</span>
                </td>
                <td class="px-2 py-2 text-center text-xs text-gray-400 font-mono">{{ line.code || '' }}</td>
                <td class="px-5 py-2 text-right font-medium"
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
          <!-- Drill-down links -->
          <div class="px-5 py-3 border-t border-gray-100 flex flex-wrap gap-2">
            <a :href="ledgerUrl('511')" class="text-xs text-primary-600 hover:underline">Xem chi tiết TK 511</a>
            <span class="text-gray-300">|</span>
            <a :href="ledgerUrl('632')" class="text-xs text-primary-600 hover:underline">Xem chi tiết TK 632</a>
            <span class="text-gray-300">|</span>
            <a :href="ledgerUrl('642')" class="text-xs text-primary-600 hover:underline">Xem chi tiết TK 642</a>
            <span class="text-gray-300">|</span>
            <a :href="route('accounting.journal-entries.index')" class="text-xs text-yellow-600 hover:underline">Xem bút toán chưa post</a>
          </div>
        </div>

        <!-- Monthly breakdown -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
          <div class="bg-gray-50 border-b border-gray-200 px-5 py-3">
            <h2 class="font-semibold text-gray-800">Phân tích theo tháng — {{ currentYear }}</h2>
          </div>
          <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200">
              <tr>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs">Tháng</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Doanh thu</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-600 text-xs">Tổng chi phí</th>
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

      <!-- Drill-down: Báo cáo quản trị dự án (ghi chú rõ) -->
      <div v-if="drillDown?.unposted_invoices > 0 || drillDown?.by_account?.length"
        class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm">
        <p class="font-semibold text-amber-800 mb-2">Thông tin bổ sung (quản trị — không phải KQHĐKD chính thức)</p>
        <div v-if="drillDown?.unposted_invoices > 0" class="text-amber-700 text-xs mb-1">
          Có <strong>{{ drillDown.unposted_invoices }}</strong> hóa đơn bán hàng chưa có bút toán GL posted trong kỳ.
          Các hóa đơn này <strong>không</strong> được tính vào KQHĐKD chính thức.
          <a :href="route('sales.invoices.index')" class="underline ml-1">Xem danh sách hóa đơn</a>
        </div>
        <p class="text-amber-600 text-xs italic">
          Báo cáo chi phí dự án WIP (TK 154) và chi phí dở dang không được phản ánh trong KQHĐKD chính thức
          cho đến khi dự án được nghiệm thu và kết chuyển sang TK 632.
        </p>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import { useCurrency } from '@/composables/useCurrency';
import { useInertiaLoading } from '@/composables/useInertiaLoading';

const props = defineProps({
  statement:   Array,
  monthly:     Array,
  summary:     Object,
  warnings:    Array,
  drillDown:   Object,
  filters:     Object,
  currentYear: Number,
  dateFrom:    String,
  dateTo:      String,
});

const { formatVnd: fmt } = useCurrency();
const { isLoading } = useInertiaLoading();

const year     = ref(props.filters?.year      ?? props.currentYear);
const dateFrom = ref(props.filters?.date_from ?? '');
const dateTo   = ref(props.filters?.date_to   ?? '');

const yearOptions = computed(() => {
  const current = new Date().getFullYear();
  return [current - 2, current - 1, current, current + 1];
});

const periodLabel = computed(() => {
  if (props.dateFrom && props.dateTo) return `${props.dateFrom} – ${props.dateTo}`;
  return `Năm ${props.currentYear}`;
});

const exportUrl = computed(() => {
  const params = new URLSearchParams();
  params.set('year', year.value ?? props.currentYear);
  if (dateFrom.value) params.set('date_from', dateFrom.value);
  if (dateTo.value)   params.set('date_to',   dateTo.value);
  return route('reports.income_statement.export') + '?' + params.toString();
});

// Link tới Account Ledger cho một TK, với bộ lọc kỳ hiện tại
function ledgerUrl(accountCode) {
  const params = new URLSearchParams();
  params.set('account_code', accountCode);
  if (props.dateFrom) params.set('date_from', props.dateFrom);
  if (props.dateTo)   params.set('date_to',   props.dateTo);
  return route('reports.account_ledger') + '?' + params.toString();
}

function onYearChange() {
  dateFrom.value = '';
  dateTo.value   = '';
  applyFilters();
}

function applyFilters() {
  router.get(route('reports.income_statement'), {
    year:      year.value     || undefined,
    date_from: dateFrom.value || undefined,
    date_to:   dateTo.value   || undefined,
  }, { preserveState: true, replace: true });
}
</script>
