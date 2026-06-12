<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Cân đối phát sinh</h1>
          <p class="text-sm text-gray-500 mt-0.5">Bảng cân đối số phát sinh theo tài khoản</p>
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
        <button @click="applyFilters" :disabled="isLoading"
          class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-70 disabled:cursor-not-allowed">
          <svg v-if="isLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Cập nhật
        </button>

        <!-- Mode toggle -->
        <div class="ml-auto flex items-center gap-1 bg-gray-100 rounded-lg p-1">
          <button @click="setMode('adjusted')"
            :class="mode === 'adjusted' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
            class="px-3 py-1.5 rounded-md text-xs font-medium transition-all">
            Chuẩn hóa
          </button>
          <button @click="setMode('raw')"
            :class="mode === 'raw' ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
            class="px-3 py-1.5 rounded-md text-xs font-medium transition-all">
            Đầy đủ (Raw)
          </button>
        </div>
      </div>

      <!-- Adjusted mode notice -->
      <div v-if="mode === 'adjusted' && hiddenCount > 0"
        class="bg-amber-50 border border-amber-200 rounded-lg px-5 py-3 flex items-center gap-3 text-sm text-amber-800">
        <svg class="w-4 h-4 flex-shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <span>
          <strong>{{ hiddenCount }} tài khoản tổng hợp</strong> có ghi nợ/có trực tiếp (dữ liệu legacy) —
          đang ẩn, thay bằng dòng roll-up <strong>∑</strong> từ TK chi tiết.
          Tổng cộng cuối bảng vẫn tính đầy đủ (bao gồm cả ghi trực tiếp). Chuyển sang <strong>Đầy đủ</strong> để xem audit trail.
        </span>
      </div>

      <!-- Raw mode notice -->
      <div v-if="mode === 'raw'"
        class="bg-gray-50 border border-gray-200 rounded-lg px-5 py-3 flex items-center gap-3 text-sm text-gray-600">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
        </svg>
        <span>
          Chế độ <strong>Đầy đủ (Raw)</strong>: hiển thị direct postings vào tất cả TK kể cả TK tổng hợp.
          Dòng <span class="text-orange-600 font-semibold">⚠</span> là TK cha có ghi trực tiếp (nên = 0 sau reclassification). Dùng để kiểm tra audit trail.
        </span>
      </div>

      <!-- Hướng dẫn đọc báo cáo -->
      <div class="bg-blue-50 border border-blue-200 rounded-lg px-5 py-3 text-sm text-blue-800 space-y-1">
        <p class="font-semibold">Hướng dẫn đọc Bảng cân đối số phát sinh (CDPS):</p>
        <ul class="list-disc list-inside space-y-0.5 text-blue-700">
          <li><strong>Số dư đầu kỳ:</strong> Số dư TK tại thời điểm trước ngày bắt đầu kỳ.</li>
          <li><strong>Phát sinh Nợ/Có:</strong> Tổng phát sinh trong kỳ được chọn.</li>
          <li><strong>Số dư cuối kỳ:</strong> = Đầu kỳ + Phát sinh trong kỳ.</li>
          <li><strong>Kiểm tra:</strong> Tổng Nợ = Tổng Có ở cả 3 cột → bút toán cân bằng.</li>
          <li><strong class="text-sky-700">∑ (dòng xanh):</strong> TK tổng hợp — hiển thị roll-up từ TK chi tiết (không phải direct posting).</li>
          <li><strong class="text-orange-600">⚠ (dòng cam):</strong> TK tổng hợp có ghi nợ/có trực tiếp — chỉ hiển thị trong chế độ Đầy đủ.</li>
        </ul>
      </div>

      <!-- Cảnh báo mất cân bằng -->
      <div v-if="Math.abs(totals.debit - totals.credit) >= 1"
        class="bg-red-50 border border-red-300 rounded-lg px-5 py-3 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <div>
          <p class="font-semibold text-red-800 text-sm">Cảnh báo: Tổng phát sinh Nợ ≠ Tổng phát sinh Có!</p>
          <p class="text-red-700 text-xs mt-0.5">
            Chênh lệch: {{ fmt(Math.abs(totals.debit - totals.credit)) }} —
            Có thể do bút toán lỗi hoặc dữ liệu chưa đầy đủ. Kiểm tra lại Sổ nhật ký chung.
          </p>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden transition-opacity" :class="{ 'opacity-60': isLoading }">
        <div class="bg-gray-50 border-b border-gray-200 px-5 py-3">
          <h2 class="font-semibold text-gray-800">BẢNG CÂN ĐỐI SỐ PHÁT SINH — {{ currentYear }}</h2>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b-2 border-gray-300">
              <tr class="bg-gray-50">
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs" rowspan="2">TK</th>
                <th class="text-left px-4 py-2 font-semibold text-gray-600 text-xs" rowspan="2">Tên tài khoản</th>
                <th class="text-center px-4 py-2 font-semibold text-gray-600 text-xs border-l border-gray-200" colspan="2">Số dư đầu kỳ</th>
                <th class="text-center px-4 py-2 font-semibold text-gray-600 text-xs border-l border-gray-200" colspan="2">Số phát sinh</th>
                <th class="text-center px-4 py-2 font-semibold text-gray-600 text-xs border-l border-gray-200" colspan="2">Số dư cuối kỳ</th>
              </tr>
              <tr class="bg-gray-50 border-b border-gray-200">
                <th class="text-right px-4 py-2 font-semibold text-gray-500 text-xs border-l border-gray-200">Nợ</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-500 text-xs">Có</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-500 text-xs border-l border-gray-200">Nợ</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-500 text-xs">Có</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-500 text-xs border-l border-gray-200">Nợ</th>
                <th class="text-right px-4 py-2 font-semibold text-gray-500 text-xs">Có</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="acc in accounts" :key="acc.code"
                :class="[
                  acc.is_rollup  ? 'bg-sky-50 hover:bg-sky-100' :
                  !acc.is_detail ? 'bg-orange-50 hover:bg-orange-100' :
                                   'hover:bg-gray-50',
                ]">
                <td class="px-4 py-2 font-mono font-semibold text-xs"
                  :class="acc.is_rollup ? 'text-sky-800' : (!acc.is_detail ? 'text-orange-700' : 'text-gray-800')">
                  {{ acc.code }}
                  <span v-if="acc.is_rollup"  class="ml-1 text-sky-500 text-xs font-normal" title="Roll-up từ TK chi tiết">∑</span>
                  <span v-if="!acc.is_detail && !acc.is_rollup" class="ml-1 text-orange-400 text-xs font-normal" title="Ghi trực tiếp vào TK tổng hợp (legacy)">⚠</span>
                </td>
                <td class="px-4 py-2 text-xs font-semibold"
                  :class="acc.is_rollup ? 'text-sky-800' : (!acc.is_detail ? 'text-orange-700 italic font-normal' : 'text-gray-700 font-normal')">
                  {{ acc.name }}
                </td>
                <td class="px-4 py-2 text-right text-xs border-l border-gray-100">{{ acc.openingDebit > 0 ? fmt(acc.openingDebit) : '—' }}</td>
                <td class="px-4 py-2 text-right text-xs">{{ acc.openingCredit > 0 ? fmt(acc.openingCredit) : '—' }}</td>
                <td class="px-4 py-2 text-right text-blue-700 text-xs border-l border-gray-100">{{ acc.dr > 0 ? fmt(acc.dr) : '—' }}</td>
                <td class="px-4 py-2 text-right text-green-700 text-xs">{{ acc.cr > 0 ? fmt(acc.cr) : '—' }}</td>
                <td class="px-4 py-2 text-right font-semibold text-xs border-l border-gray-100">{{ acc.closingDebit > 0 ? fmt(acc.closingDebit) : '—' }}</td>
                <td class="px-4 py-2 text-right font-semibold text-xs">{{ acc.closingCredit > 0 ? fmt(acc.closingCredit) : '—' }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 border-t-2 border-gray-300">
              <tr>
                <td colspan="2" class="px-4 py-2 font-bold text-gray-800 text-xs">
                  TỔNG CỘNG
                  <span v-if="hiddenCount > 0" class="font-normal text-gray-500 text-xs">
                    (gồm ghi trực tiếp {{ hiddenCount }} TK tổng hợp)
                  </span>
                </td>
                <td class="px-4 py-2 text-right font-bold text-xs border-l border-gray-100">{{ fmt(totals.opening_debit) }}</td>
                <td class="px-4 py-2 text-right font-bold text-xs">{{ fmt(totals.opening_credit) }}</td>
                <td class="px-4 py-2 text-right font-bold text-blue-800 text-xs border-l border-gray-100">{{ fmt(totals.debit) }}</td>
                <td class="px-4 py-2 text-right font-bold text-green-800 text-xs">{{ fmt(totals.credit) }}</td>
                <td class="px-4 py-2 text-right font-bold text-xs border-l border-gray-100">{{ fmt(totals.closing_debit) }}</td>
                <td class="px-4 py-2 text-right font-bold text-xs">{{ fmt(totals.closing_credit) }}</td>
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
import { useInertiaLoading } from '@/composables/useInertiaLoading';

const props = defineProps({
  accounts:    Array,
  totals:      Object,
  hiddenCount: { type: Number, default: 0 },
  filters:     Object,
  currentYear: Number,
});

const { formatVnd: fmt } = useCurrency();
const { isLoading } = useInertiaLoading();

const year     = ref(props.filters?.year      ?? props.currentYear);
const dateFrom = ref(props.filters?.date_from ?? '');
const dateTo   = ref(props.filters?.date_to   ?? '');
const mode     = ref(props.filters?.mode      ?? 'adjusted');

const yearOptions = computed(() => {
  const c = new Date().getFullYear();
  return [c - 2, c - 1, c, c + 1];
});

const exportUrl = computed(() => {
  const p = new URLSearchParams();
  if (year.value)     p.set('year', year.value);
  if (dateFrom.value) p.set('date_from', dateFrom.value);
  if (dateTo.value)   p.set('date_to', dateTo.value);
  p.set('mode', mode.value);
  return route('reports.trial_balance.export') + '?' + p.toString();
});

function applyFilters() {
  router.get(route('reports.trial_balance'), {
    year:      year.value     || undefined,
    date_from: dateFrom.value || undefined,
    date_to:   dateTo.value   || undefined,
    mode:      mode.value,
  }, { preserveState: true, replace: true });
}

function setMode(newMode) {
  mode.value = newMode;
  applyFilters();
}
</script>
