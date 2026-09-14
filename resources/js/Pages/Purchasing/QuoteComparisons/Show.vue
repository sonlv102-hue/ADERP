<template>
  <AppLayout>
    <div class="space-y-5">
      <!-- Header -->
      <div class="flex items-start justify-between flex-wrap gap-y-3">
        <div class="flex items-center gap-3">
          <Link :href="route('purchasing.quote-comparisons.index')" class="text-gray-500 hover:text-gray-700">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
          <div>
            <div class="flex items-center gap-3">
              <h1 class="text-2xl font-bold text-gray-900">{{ comparison.code }}</h1>
              <StatusBadge :color="comparison.status_color">{{ comparison.status_label }}</StatusBadge>
            </div>
            <p class="text-sm text-gray-500 mt-0.5">{{ comparison.name }} · {{ comparison.comparison_date }}</p>
          </div>
        </div>
        <div class="flex gap-2 flex-wrap items-center">
          <a
            v-if="can('purchases.quote_comparisons.export')"
            :href="route('purchasing.quote-comparisons.export', { quoteComparison: comparison.id, price_basis: priceBasis })"
            class="erp-btn-secondary"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
            </svg>
            Xuất Excel
          </a>
          <button
            v-if="can('purchases.quote_comparisons.update') && comparison.status !== 'completed'"
            class="erp-btn-primary"
            @click="headerAction('complete')"
          >Hoàn thành</button>
          <button
            v-if="can('purchases.quote_comparisons.update') && comparison.status === 'completed'"
            class="erp-btn-secondary"
            @click="headerAction('reopen')"
          >Mở lại</button>
          <button
            v-if="can('purchases.quote_comparisons.update') && comparison.is_draft"
            class="erp-btn-danger"
            @click="destroy"
          >Xóa</button>
        </div>
      </div>

      <!-- Tabs -->
      <div class="bg-white rounded-xl border border-gray-200">
        <div class="flex border-b border-gray-200 overflow-x-auto">
          <button
            v-for="t in tabs"
            :key="t.key"
            class="px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 -mb-px transition"
            :class="activeTab === t.key
              ? 'border-primary-600 text-primary-700'
              : 'border-transparent text-gray-500 hover:text-gray-700'"
            @click="activeTab = t.key"
          >{{ t.label }}</button>
        </div>

        <div class="p-5">
          <TabItems v-if="activeTab === 'items'" :comparison="comparison" :items="items" />
          <TabQuotes v-else-if="activeTab === 'quotes'" :comparison="comparison" :quotes="quotes" />
          <TabComparison
            v-else-if="activeTab === 'compare'"
            :comparison="comparison"
            :matrix="matrix"
            :reasons="reasons"
            :price-basis="priceBasis"
            @change-basis="changeBasis"
          />
          <TabSummary v-else :summary="summary" />
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { usePermission } from '@/composables/usePermission';
import TabItems from './Tabs/TabItems.vue';
import TabQuotes from './Tabs/TabQuotes.vue';
import TabComparison from './Tabs/TabComparison.vue';
import TabSummary from './Tabs/TabSummary.vue';

const props = defineProps({
  comparison: Object,
  items: Array,
  quotes: Array,
  matrix: Object,
  summary: Object,
  reasons: Array,
  priceBasis: String,
});

const { hasPermission: can } = usePermission();

const tabs = [
  { key: 'items', label: '1. Danh sách hàng' },
  { key: 'quotes', label: '2. Báo giá NCC' },
  { key: 'compare', label: '3. So sánh giá' },
  { key: 'summary', label: '4. Tổng hợp lựa chọn' },
];
const activeTab = ref('items');

function headerAction(action) {
  router.put(route('purchasing.quote-comparisons.update', props.comparison.id), { action }, { preserveScroll: true });
}

function destroy() {
  if (confirm('Xóa đợt so sánh này?')) {
    router.delete(route('purchasing.quote-comparisons.destroy', props.comparison.id));
  }
}

function changeBasis(basis) {
  router.get(
    route('purchasing.quote-comparisons.show', props.comparison.id),
    { price_basis: basis },
    { preserveState: true, preserveScroll: true, replace: true },
  );
}
</script>
