<template>
  <Modal :show="true" max-width="5xl" @close="$emit('close')">
    <template #title>{{ kpiMeta.label }} — Chi tiết</template>

    <div class="space-y-4">
      <p class="text-xs text-gray-500">
        Kỳ {{ formatDate(filters.date_from) }} – {{ formatDate(filters.date_to) }}
        <span v-if="filters.warehouse_id || filters.category_id || filters.search"> · theo bộ lọc đang áp dụng trên báo cáo</span>
      </p>

      <div class="flex gap-1 border-b border-gray-200">
        <button v-for="t in tabs" :key="t.key" @click="activeTab = t.key"
          :class="['px-3 py-2 text-sm font-medium border-b-2 -mb-px',
            activeTab === t.key ? 'border-primary-600 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-700']">
          {{ t.label }}
        </button>
      </div>

      <!-- Tổng hợp -->
      <div v-if="activeTab === 'summary'" class="space-y-3">
        <p v-if="summaryLoading" class="text-sm text-gray-400">Đang tải...</p>
        <template v-else-if="summary">
          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-lg border border-gray-200 p-3">
              <p class="text-xs text-gray-500">Giá trị KPI (báo cáo gốc)</p>
              <p class="text-lg font-bold" :class="kpiMeta.textClass">{{ formatVnd(summary.kpi_value) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 p-3">
              <p class="text-xs text-gray-500">Số dòng chứng từ / Tổng SL</p>
              <p class="text-lg font-bold text-gray-800">{{ summary.document_count }} dòng · {{ fmtQty(summary.total_qty) }}</p>
            </div>
          </div>
          <NullValueWarning :stats="summary.null_value_stats" />
        </template>
      </div>

      <!-- Theo sản phẩm -->
      <DrilldownProductsTab v-if="activeTab === 'products'" :filters="filters" :kpi="kpi" />

      <!-- Chứng từ -->
      <DrilldownDocumentsTab v-if="activeTab === 'documents'" :filters="filters" :kpi="kpi" />

      <!-- Đối soát -->
      <div v-if="activeTab === 'reconcile'" class="space-y-3">
        <p v-if="reconcileLoading" class="text-sm text-gray-400">Đang tải...</p>
        <template v-else-if="reconcile">
          <table class="w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
            <tbody class="divide-y divide-gray-100">
              <tr><td class="px-3 py-2 text-gray-500">Giá trị KPI (báo cáo gốc)</td><td class="px-3 py-2 text-right font-medium">{{ formatVnd(reconcile.kpi_value) }}</td></tr>
              <tr><td class="px-3 py-2 text-gray-500">Tổng chi tiết Drill-down</td><td class="px-3 py-2 text-right font-medium">{{ formatVnd(reconcile.detail_total) }}</td></tr>
              <tr :class="reconcile.matched ? 'bg-green-50' : 'bg-red-50'">
                <td class="px-3 py-2 font-medium">{{ reconcile.matched ? 'Khớp' : 'Chênh lệch' }}</td>
                <td class="px-3 py-2 text-right font-bold" :class="reconcile.matched ? 'text-green-700' : 'text-red-700'">{{ formatVnd(reconcile.diff) }}</td>
              </tr>
            </tbody>
          </table>
          <NullValueWarning :stats="reconcile.null_value_stats" />
        </template>
      </div>
    </div>

    <template #footer>
      <a :href="exportUrl" target="_blank" class="erp-btn-secondary">Xuất Excel chứng từ</a>
      <button class="erp-btn-secondary" @click="$emit('close')">Đóng</button>
    </template>
  </Modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import Modal from '@/Components/Shared/Modal.vue';
import { formatVnd } from '@/composables/useCurrency';
import DrilldownProductsTab from './DrilldownProductsTab.vue';
import DrilldownDocumentsTab from './DrilldownDocumentsTab.vue';
import NullValueWarning from './NullValueWarning.vue';

const props = defineProps({
  kpi:     { type: String, required: true }, // begin|in|out|end
  filters: { type: Object, required: true },
});
defineEmits(['close']);

const KPI_META = {
  begin: { label: 'Giá trị tồn đầu kỳ', textClass: 'text-gray-800' },
  in:    { label: 'Giá trị nhập trong kỳ', textClass: 'text-blue-700' },
  out:   { label: 'Giá trị xuất trong kỳ', textClass: 'text-orange-700' },
  end:   { label: 'Giá trị tồn cuối kỳ', textClass: 'text-green-700' },
};
const kpiMeta = computed(() => KPI_META[props.kpi] ?? KPI_META.begin);

const tabs = [
  { key: 'summary',   label: 'Tổng hợp' },
  { key: 'products',  label: 'Theo sản phẩm' },
  { key: 'documents', label: 'Chứng từ' },
  { key: 'reconcile', label: 'Đối soát' },
];
const activeTab = ref('summary');

const summary = ref(null);
const summaryLoading = ref(false);
const reconcile = ref(null);
const reconcileLoading = ref(false);

async function loadSummary() {
  summaryLoading.value = true;
  try {
    const { data } = await axios.get(route('reports.inventory.drilldown'), { params: { ...props.filters, kpi: props.kpi, tab: 'summary' } });
    summary.value = data;
  } finally {
    summaryLoading.value = false;
  }
}

async function loadReconcile() {
  reconcileLoading.value = true;
  try {
    const { data } = await axios.get(route('reports.inventory.drilldown'), { params: { ...props.filters, kpi: props.kpi, tab: 'reconcile' } });
    reconcile.value = data;
  } finally {
    reconcileLoading.value = false;
  }
}

watch(activeTab, (tab) => {
  if (tab === 'summary' && !summary.value) loadSummary();
  if (tab === 'reconcile' && !reconcile.value) loadReconcile();
}, { immediate: true });

const exportUrl = computed(() => {
  const params = new URLSearchParams({ ...props.filters, kpi: props.kpi });
  return route('reports.inventory.drilldown.export') + '?' + params.toString();
});

function fmtQty(n) { return Number(n ?? 0).toLocaleString('vi-VN'); }
function formatDate(d) {
  if (!d) return '—';
  const [y, m, day] = d.split('-');
  return `${day}/${m}/${y}`;
}
</script>
