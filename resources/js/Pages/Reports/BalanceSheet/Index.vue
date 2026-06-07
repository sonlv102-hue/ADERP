<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Cân đối kế toán</h1>
          <p class="text-sm text-gray-500 mt-0.5">Bảng cân đối kế toán tại thời điểm</p>
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
          <label class="text-sm text-gray-600 font-medium">Tại ngày:</label>
          <input v-model="asOf" type="date"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>
        <button @click="applyFilters" :disabled="isLoading"
          class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-70 disabled:cursor-not-allowed">
          <svg v-if="isLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Xem báo cáo
        </button>
      </div>

      <!-- Hướng dẫn đọc báo cáo -->
      <div class="bg-blue-50 border border-blue-200 rounded-lg px-5 py-3 text-sm text-blue-800 space-y-1">
        <p class="font-semibold">📋 Hướng dẫn đọc Bảng cân đối kế toán (BCĐKT):</p>
        <ul class="list-disc list-inside space-y-0.5 text-blue-700">
          <li><strong>Tài sản = Nợ phải trả + Vốn chủ sở hữu</strong> — đây là nguyên tắc kép của kế toán.</li>
          <li><strong>Tài sản ngắn hạn:</strong> Tiền, phải thu, hàng tồn kho — dự kiến thu hồi trong 12 tháng.</li>
          <li><strong>Vốn chủ:</strong> Vốn góp + Lợi nhuận chưa phân phối (lũy kế từ P&amp;L).</li>
          <li><strong>Lưu ý:</strong> Báo cáo tại <em>ngày</em> được chọn, không phải khoảng thời gian.</li>
        </ul>
      </div>

      <!-- Balance check -->
      <div v-if="!summary.balanced" class="bg-red-50 border border-red-300 rounded-lg p-4 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <div>
          <p class="font-semibold text-red-800 text-sm">Bảng cân đối không khớp!</p>
          <p class="text-red-700 text-xs mt-0.5">
            Tổng tài sản ({{ fmt(summary.total_assets) }}) ≠ Tổng nguồn vốn ({{ fmt(summary.total_liabilities_equity) }}).
            Chênh lệch: {{ fmt(Math.abs(summary.total_assets - summary.total_liabilities_equity)) }}.
            Nguyên nhân thường gặp: chưa nhập số dư đầu kỳ vốn góp (TK 411), hoặc có bút toán thiếu cân bằng.
          </p>
        </div>
      </div>
      <div v-else class="bg-green-50 border border-green-200 rounded-lg px-4 py-2.5 text-sm text-green-700 flex items-center gap-2">
        <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        Bảng cân đối cân bằng — Tài sản = Nguồn vốn = {{ fmt(summary.total_assets) }}
      </div>

      <!-- KPI -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 transition-opacity" :class="{ 'opacity-60': isLoading }">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng tài sản</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(summary.total_assets) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Nợ phải trả</p>
          <p class="text-lg font-bold text-red-700">{{ fmt(summary.total_liabilities) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Vốn chủ sở hữu</p>
          <p class="text-lg font-bold" :class="summary.total_equity >= 0 ? 'text-green-700' : 'text-red-700'">{{ fmt(summary.total_equity) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng nguồn vốn</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(summary.total_liabilities_equity) }}</p>
        </div>
      </div>

      <!-- Two-column layout -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 transition-opacity" :class="{ 'opacity-60': isLoading }">
        <!-- Assets -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="bg-blue-50 border-b border-gray-200 px-5 py-3">
            <h2 class="font-semibold text-blue-800">TÀI SẢN</h2>
          </div>
          <table class="w-full text-sm">
            <tbody>
              <template v-for="(row, i) in assetRows" :key="i">
                <tr class="border-b border-gray-100 last:border-0"
                  :class="[row.side === 'total_asset' ? 'bg-blue-50' : row.bold ? 'bg-gray-50' : 'hover:bg-gray-50']">
                  <td class="px-5 py-2.5 text-gray-700"
                    :class="[indentClass(row.indent), row.bold || row.side === 'total_asset' ? 'font-semibold text-gray-900' : '']">
                    {{ row.label }}
                  </td>
                  <td class="px-5 py-2.5 text-right font-medium"
                    :class="row.side === 'total_asset' ? 'font-bold text-blue-800' : row.amount < 0 ? 'text-red-600' : 'text-gray-800'">
                    {{ row.amount !== null ? fmt(Math.abs(row.amount)) : '' }}
                    <span v-if="row.amount !== null && row.amount < 0" class="text-xs">(−)</span>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <!-- Liabilities + Equity -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="bg-green-50 border-b border-gray-200 px-5 py-3">
            <h2 class="font-semibold text-green-800">NGUỒN VỐN</h2>
          </div>
          <table class="w-full text-sm">
            <tbody>
              <template v-for="(row, i) in equityRows" :key="i">
                <tr class="border-b border-gray-100 last:border-0"
                  :class="[row.side === 'total_equity' ? 'bg-green-50' : row.bold ? 'bg-gray-50' : 'hover:bg-gray-50']">
                  <td class="px-5 py-2.5 text-gray-700"
                    :class="[indentClass(row.indent), row.bold || row.side === 'total_equity' ? 'font-semibold text-gray-900' : '']">
                    {{ row.label }}
                  </td>
                  <td class="px-5 py-2.5 text-right font-medium"
                    :class="row.side === 'total_equity' ? 'font-bold text-green-800' : row.amount < 0 ? 'text-red-600' : 'text-gray-800'">
                    {{ row.amount !== null ? fmt(row.amount) : '' }}
                  </td>
                </tr>
              </template>
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
import { useInertiaLoading } from '@/composables/useInertiaLoading';

const props = defineProps({
  balanceSheet: Array,
  summary:      Object,
  filters:      Object,
});

const { formatVnd: fmt } = useCurrency();
const { isLoading } = useInertiaLoading();

const asOf = ref(props.filters?.as_of ?? new Date().toISOString().slice(0, 10));

const assetRows  = computed(() => props.balanceSheet.filter(r => ['asset', 'total_asset'].includes(r.side)));
const equityRows = computed(() => props.balanceSheet.filter(r => ['liability', 'equity', 'total_equity'].includes(r.side)));

const exportUrl = computed(() => route('reports.balance_sheet.export') + '?as_of=' + asOf.value);

function indentClass(indent) {
  return ['', 'pl-6', 'pl-10'][indent] ?? '';
}

function applyFilters() {
  router.get(route('reports.balance_sheet'), { as_of: asOf.value }, { preserveState: true, replace: true });
}
</script>
