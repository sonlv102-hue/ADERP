<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Cân đối kế toán</h1>
          <p class="text-sm text-gray-500 mt-0.5">
            {{ reportMeta?.report_name }} — Mẫu {{ reportMeta?.report_code }}
            ({{ reportMeta?.circular }})
          </p>
        </div>
        <a :href="exportUrl"
          class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
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

      <!-- Cảnh báo -->
      <div v-if="warnings?.length" class="space-y-2">
        <div v-for="(w, i) in warnings" :key="i"
          class="bg-yellow-50 border border-yellow-300 rounded-lg px-4 py-3 flex items-start gap-2">
          <svg class="w-4 h-4 text-yellow-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
          </svg>
          <p class="text-sm text-yellow-800">{{ w }}</p>
        </div>
      </div>

      <!-- Trial Balance status -->
      <div v-if="trialBalance && !trialBalance.balanced"
        class="bg-red-50 border border-red-300 rounded-lg px-4 py-3 text-sm text-red-800">
        <p class="font-semibold">Trial Balance chưa cân — B01a-DNN có thể không đáng tin cậy</p>
        <p class="mt-0.5 text-red-700">
          Tổng Nợ: {{ fmt(trialBalance.total_debit) }} |
          Tổng Có: {{ fmt(trialBalance.total_credit) }} |
          Lệch: {{ fmt(Math.abs(trialBalance.difference)) }}
        </p>
      </div>

      <!-- Balance check -->
      <div v-if="!summary.balanced"
        class="bg-red-50 border border-red-300 rounded-lg p-4 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <div>
          <p class="font-semibold text-red-800 text-sm">
            Báo cáo chưa cân — mã 200 ≠ mã 500
          </p>
          <p class="text-red-700 text-xs mt-0.5">
            Tổng tài sản ({{ fmt(summary.total_assets) }}) ≠
            Tổng nguồn vốn ({{ fmt(summary.total_liabilities_equity) }}).
            Chênh lệch: {{ fmt(Math.abs(summary.difference)) }}
          </p>
        </div>
      </div>
      <div v-else class="bg-green-50 border border-green-200 rounded-lg px-4 py-2.5 text-sm text-green-700 flex items-center gap-2">
        <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
        Báo cáo đã cân — Tổng tài sản = Tổng nguồn vốn = {{ fmt(summary.total_assets) }}
      </div>

      <!-- KPI -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 transition-opacity" :class="{ 'opacity-60': isLoading }">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng tài sản (200)</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(summary.total_assets) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Nợ phải trả (300)</p>
          <p class="text-lg font-bold text-red-700">{{ fmt(summary.total_liabilities) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Vốn chủ sở hữu (400)</p>
          <p class="text-lg font-bold"
            :class="summary.total_equity >= 0 ? 'text-green-700' : 'text-red-700'">
            {{ fmt(summary.total_equity) }}
          </p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng nguồn vốn (500)</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(summary.total_liabilities_equity) }}</p>
        </div>
      </div>

      <!-- Two-column report -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 transition-opacity" :class="{ 'opacity-60': isLoading }">
        <!-- TÀI SẢN -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="bg-blue-50 border-b border-gray-200 px-5 py-3 flex items-center justify-between">
            <h2 class="font-semibold text-blue-800">PHẦN I — TÀI SẢN</h2>
            <span class="text-xs text-blue-600 font-mono">Mã 200 = {{ fmt(summary.total_assets) }}</span>
          </div>
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 bg-gray-50 text-xs text-gray-500">
                <th class="px-3 py-2 text-center w-12 font-medium">Mã</th>
                <th class="px-3 py-2 text-left font-medium">Chỉ tiêu</th>
                <th class="px-3 py-2 text-right font-medium">Số tiền (đ)</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(row, i) in assetRows" :key="i">
                <tr class="border-b border-gray-100 last:border-0"
                  :class="[
                    row.is_total ? 'bg-blue-50' :
                    row.level === 1 && row.is_formula ? 'bg-gray-50' : 'hover:bg-gray-50'
                  ]">
                  <td class="px-3 py-2 text-center text-xs font-mono"
                    :class="row.is_total ? 'font-bold text-blue-700' : 'text-gray-400'">
                    {{ row.item_code ?? '' }}
                  </td>
                  <td class="py-2 text-gray-700"
                    :class="[
                      row.level === 2 ? 'pl-8 pr-3' : 'pl-3 pr-3',
                      row.is_total || (row.level === 1 && row.is_formula) ? 'font-semibold text-gray-900' : ''
                    ]">
                    {{ row.item_name }}
                  </td>
                  <td class="px-3 py-2 text-right font-medium"
                    :class="[
                      row.is_total ? 'font-bold text-blue-800' :
                      row.amount < 0 ? 'text-red-600' : 'text-gray-800'
                    ]">
                    {{ row.amount !== 0 || row.is_total ? fmt(row.amount) : '—' }}
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <!-- NGUỒN VỐN -->
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="bg-green-50 border-b border-gray-200 px-5 py-3 flex items-center justify-between">
            <h2 class="font-semibold text-green-800">PHẦN II — NGUỒN VỐN</h2>
            <span class="text-xs text-green-600 font-mono">Mã 500 = {{ fmt(summary.total_liabilities_equity) }}</span>
          </div>
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-100 bg-gray-50 text-xs text-gray-500">
                <th class="px-3 py-2 text-center w-12 font-medium">Mã</th>
                <th class="px-3 py-2 text-left font-medium">Chỉ tiêu</th>
                <th class="px-3 py-2 text-right font-medium">Số tiền (đ)</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(row, i) in sourceRows" :key="i">
                <tr class="border-b border-gray-100 last:border-0"
                  :class="[
                    row.is_total ? 'bg-green-50' :
                    row.is_section_header ? 'bg-gray-50' :
                    row.level === 1 && row.is_formula ? 'bg-gray-50' : 'hover:bg-gray-50'
                  ]">
                  <td class="px-3 py-2 text-center text-xs font-mono"
                    :class="row.is_total || row.is_section_header ? 'font-bold text-green-700' : 'text-gray-400'">
                    {{ row.item_code ?? '' }}
                  </td>
                  <td class="py-2 text-gray-700"
                    :class="[
                      row.level === 2 ? 'pl-8 pr-3' : 'pl-3 pr-3',
                      row.is_total || row.is_section_header || (row.level === 1 && row.is_formula)
                        ? 'font-semibold text-gray-900' : ''
                    ]">
                    {{ row.item_name }}
                  </td>
                  <td class="px-3 py-2 text-right font-medium"
                    :class="[
                      row.is_total ? 'font-bold text-green-800' :
                      row.amount < 0 ? 'text-red-600' : 'text-gray-800'
                    ]">
                    {{ row.amount !== 0 || row.is_total || row.is_section_header ? fmt(row.amount) : '—' }}
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
  warnings:     Array,
  trialBalance: Object,
  reportMeta:   Object,
  filters:      Object,
});

const { formatVnd: fmt } = useCurrency();
const { isLoading } = useInertiaLoading();

const asOf = ref(props.filters?.as_of ?? new Date().toISOString().slice(0, 10));

const assetRows  = computed(() => (props.balanceSheet ?? []).filter(r => r.section === 'asset'));
const sourceRows = computed(() => (props.balanceSheet ?? []).filter(r => r.section === 'source'));

const exportUrl = computed(() => route('reports.balance_sheet.export') + '?as_of=' + asOf.value);

function applyFilters() {
  router.get(route('reports.balance_sheet'), { as_of: asOf.value }, { preserveState: true, replace: true });
}
</script>
