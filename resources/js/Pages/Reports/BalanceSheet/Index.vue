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
        <div class="flex gap-2">
          <a :href="exportUrl"
            class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Xuất Excel
          </a>
        </div>
      </div>

      <!-- Filter -->
      <div class="flex gap-3 items-center flex-wrap">
        <div class="flex items-center gap-2">
          <label class="text-sm text-gray-600 font-medium">Tại ngày:</label>
          <input v-model="asOf" type="date"
            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" />
        </div>
        <button @click="applyFilters" :disabled="isLoading"
          class="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-70">
          <svg v-if="isLoading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          Tính lại
        </button>
      </div>

      <!-- Warnings -->
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

      <!-- Balance status bar -->
      <div v-if="!summary.balanced"
        class="bg-red-50 border border-red-300 rounded-lg p-4 flex items-start gap-3">
        <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <div>
          <p class="font-semibold text-red-800 text-sm">Báo cáo chưa cân — mã 200 ≠ mã 500</p>
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

      <!-- KPI cards -->
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
          <p class="text-lg font-bold" :class="summary.total_equity >= 0 ? 'text-green-700' : 'text-red-700'">
            {{ fmt(summary.total_equity) }}
          </p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
          <p class="text-xs text-gray-500 mb-1">Tổng nguồn vốn (500)</p>
          <p class="text-lg font-bold text-gray-900">{{ fmt(summary.total_liabilities_equity) }}</p>
        </div>
      </div>

      <!-- Tabs -->
      <div class="flex gap-1 border-b border-gray-200">
        <button v-for="tab in tabs" :key="tab.id" @click="activeTab = tab.id"
          class="px-4 py-2 text-sm font-medium rounded-t-lg transition-colors"
          :class="activeTab === tab.id
            ? 'bg-white border border-b-white border-gray-200 text-primary-700 -mb-px'
            : 'text-gray-500 hover:text-gray-700'">
          {{ tab.label }}
          <span v-if="tab.id === 'unmapped' && unmappedAccounts?.length"
            class="ml-1.5 inline-flex items-center justify-center w-5 h-5 rounded-full bg-orange-500 text-white text-xs font-bold">
            {{ unmappedAccounts.length }}
          </span>
        </button>
      </div>

      <!-- Tab: Bảng cân đối -->
      <div v-show="activeTab === 'report'" class="transition-opacity" :class="{ 'opacity-60': isLoading }">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
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

      <!-- Tab: TK chưa map -->
      <div v-show="activeTab === 'unmapped'" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 bg-orange-50">
          <h2 class="font-semibold text-orange-800">Tài khoản có số dư nhưng chưa map vào B01a-DNN</h2>
          <p class="text-xs text-orange-600 mt-0.5">
            Những TK này có số dư thực tế nhưng không được phản ánh trong báo cáo.
            Kiểm tra file <code>config/accounting_reports_tt133.php</code> để bổ sung mapping.
          </p>
        </div>
        <div v-if="!unmappedAccounts?.length" class="px-5 py-8 text-center text-green-600 text-sm">
          <svg class="w-8 h-8 mx-auto mb-2 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          Tất cả tài khoản có số dư đã được map vào báo cáo.
        </div>
        <table v-else class="w-full text-sm">
          <thead class="border-b border-gray-200 bg-gray-50">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã TK</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên tài khoản</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số dư</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ghi chú</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="acc in unmappedAccounts" :key="acc.code" class="hover:bg-orange-50">
              <td class="px-5 py-3 font-mono font-semibold text-orange-700">{{ acc.code }}</td>
              <td class="px-5 py-3 text-gray-700">{{ acc.name }}</td>
              <td class="px-5 py-3 text-right" :class="acc.balance < 0 ? 'text-red-600 font-semibold' : 'text-gray-900 font-medium'">
                {{ fmt(acc.balance) }}
              </td>
              <td class="px-5 py-3 text-xs text-orange-600">
                Chưa map vào chỉ tiêu báo cáo
              </td>
            </tr>
          </tbody>
          <tfoot class="border-t-2 border-gray-300 bg-orange-50">
            <tr>
              <td colspan="2" class="px-5 py-3 font-semibold text-gray-700">Tổng giá trị chưa map</td>
              <td class="px-5 py-3 text-right font-bold text-orange-700">
                {{ fmt(unmappedTotal) }}
              </td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Tab: Kiểm tra cân đối -->
      <div v-show="activeTab === 'check'" class="space-y-4">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
            <h2 class="font-semibold text-gray-800">Kiểm tra cân đối</h2>
          </div>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-100">
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Tổng tài sản (Mã 200)</td>
                <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ fmt(summary.total_assets) }}</td>
                <td class="px-5 py-3 w-8">
                  <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </td>
              </tr>
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Nợ phải trả (Mã 300)</td>
                <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ fmt(summary.total_liabilities) }}</td>
                <td></td>
              </tr>
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Vốn chủ sở hữu (Mã 400)</td>
                <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ fmt(summary.total_equity) }}</td>
                <td></td>
              </tr>
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Tổng nguồn vốn (Mã 500)</td>
                <td class="px-5 py-3 text-right font-semibold text-gray-900">{{ fmt(summary.total_liabilities_equity) }}</td>
                <td></td>
              </tr>
              <tr class="bg-gray-50 border-t-2 border-gray-300">
                <td class="px-5 py-3 font-bold text-gray-800">Chênh lệch (200 − 500)</td>
                <td class="px-5 py-3 text-right font-bold text-xl"
                  :class="summary.balanced ? 'text-green-600' : 'text-red-600'">
                  {{ fmt(summary.difference) }}
                </td>
                <td class="px-5 py-3">
                  <svg v-if="summary.balanced" class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                  <svg v-else class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Trial balance -->
        <div v-if="trialBalance" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-200 bg-gray-50">
            <h2 class="font-semibold text-gray-800">Trạng thái Trial Balance</h2>
          </div>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-100">
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Tổng phát sinh Nợ</td>
                <td class="px-5 py-3 text-right font-semibold">{{ fmt(trialBalance.total_debit) }}</td>
              </tr>
              <tr class="hover:bg-gray-50">
                <td class="px-5 py-3 text-gray-600">Tổng phát sinh Có</td>
                <td class="px-5 py-3 text-right font-semibold">{{ fmt(trialBalance.total_credit) }}</td>
              </tr>
              <tr class="bg-gray-50 border-t-2 border-gray-300">
                <td class="px-5 py-3 font-bold text-gray-800">Chênh lệch</td>
                <td class="px-5 py-3 text-right font-bold"
                  :class="trialBalance.balanced ? 'text-green-600' : 'text-red-600'">
                  {{ fmt(Math.abs(trialBalance.difference)) }}
                  <span class="ml-1 text-xs">{{ trialBalance.balanced ? '✓ Cân' : '⚠ Lệch' }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Unmapped summary -->
        <div v-if="unmappedAccounts?.length"
          class="bg-orange-50 border border-orange-200 rounded-xl px-5 py-4 text-sm text-orange-800">
          <p class="font-semibold">⚠ {{ unmappedAccounts.length }} tài khoản chưa được map vào báo cáo</p>
          <p class="mt-1 text-orange-600">
            Tổng giá trị chưa phản ánh: <strong>{{ fmt(unmappedTotal) }}</strong>
            — Xem tab "TK chưa map" để biết chi tiết.
          </p>
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
  balanceSheet:     Array,
  summary:          Object,
  warnings:         Array,
  trialBalance:     Object,
  unmappedAccounts: { type: Array, default: () => [] },
  reportMeta:       Object,
  filters:          Object,
});

const { formatVnd: fmt } = useCurrency();
const { isLoading }       = useInertiaLoading();

const asOf       = ref(props.filters?.as_of ?? new Date().toISOString().slice(0, 10));
const activeTab  = ref('report');

const tabs = computed(() => [
  { id: 'report',   label: 'Bảng cân đối kế toán' },
  { id: 'unmapped', label: 'TK chưa map' },
  { id: 'check',    label: 'Kiểm tra cân đối' },
]);

const assetRows  = computed(() => (props.balanceSheet ?? []).filter(r => r.section === 'asset'));
const sourceRows = computed(() => (props.balanceSheet ?? []).filter(r => r.section === 'source'));

const unmappedTotal = computed(() =>
  (props.unmappedAccounts ?? []).reduce((sum, a) => sum + Math.abs(a.balance), 0)
);

const exportUrl = computed(() => route('reports.balance_sheet.export') + '?as_of=' + asOf.value);

function applyFilters() {
  router.get(route('reports.balance_sheet'), { as_of: asOf.value }, { preserveState: true, replace: true });
}
</script>
