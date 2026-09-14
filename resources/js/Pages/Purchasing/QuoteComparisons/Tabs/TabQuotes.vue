<template>
  <div class="space-y-4">
    <div class="flex flex-wrap gap-2 justify-between items-center">
      <label class="text-sm text-gray-500 flex items-center gap-2">
        <input type="checkbox" v-model="showHistory" class="rounded border-gray-300" />
        Hiện cả phiên bản cũ
      </label>
      <button
        v-if="can('purchases.quote_comparisons.import')"
        class="erp-btn-primary"
        @click="showImport = true"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        Import báo giá NCC
      </button>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="text-left px-4 py-3 font-semibold text-gray-600">NCC</th>
            <th class="text-left px-4 py-3 font-semibold text-gray-600">Số BG</th>
            <th class="text-right px-4 py-3 font-semibold text-gray-600">Version</th>
            <th class="text-left px-4 py-3 font-semibold text-gray-600">Ngày BG</th>
            <th class="text-right px-4 py-3 font-semibold text-gray-600">Mặt hàng</th>
            <th class="text-right px-4 py-3 font-semibold text-gray-600">Giá trị chưa VAT</th>
            <th class="text-right px-4 py-3 font-semibold text-gray-600">Tổng tiền</th>
            <th class="text-left px-4 py-3 font-semibold text-gray-600">Trạng thái</th>
            <th class="px-4 py-3" />
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="q in visibleQuotes" :key="q.id" class="hover:bg-gray-50" :class="{ 'opacity-50': !q.is_active_version }">
            <td class="px-4 py-3 text-gray-900 font-medium">{{ q.supplier }}</td>
            <td class="px-4 py-3 text-gray-600">{{ q.quote_no || '—' }}</td>
            <td class="px-4 py-3 text-right text-gray-600">v{{ q.version_no }}</td>
            <td class="px-4 py-3 text-gray-600">{{ q.quote_date || '—' }}</td>
            <td class="px-4 py-3 text-right text-gray-700">{{ q.line_count }}/{{ q.item_count }}</td>
            <td class="px-4 py-3 text-right text-gray-900">{{ fmt(q.totals.subtotal) }}</td>
            <td class="px-4 py-3 text-right font-medium text-gray-900">{{ fmt(q.totals.total) }}</td>
            <td class="px-4 py-3">
              <StatusBadge :status="q.is_active_version ? 'active' : 'draft'">
                {{ q.is_active_version ? 'Đang áp dụng' : 'Phiên bản cũ' }}
              </StatusBadge>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <a
                v-if="q.has_file"
                :href="route('purchasing.quote-comparisons.quotes.file', { quoteComparison: comparison.id, quote: q.id })"
                class="text-primary-600 hover:text-primary-800 text-xs font-medium mr-3"
              >Xem file</a>
              <button
                v-if="can('purchases.quote_comparisons.import') && q.is_active_version"
                class="text-red-600 hover:text-red-800 text-xs font-medium"
                @click="removeQuote(q)"
              >Gỡ</button>
            </td>
          </tr>
          <tr v-if="!visibleQuotes.length">
            <td colspan="9" class="px-4 py-10 text-center text-gray-400">Chưa có báo giá NCC nào</td>
          </tr>
        </tbody>
      </table>
    </div>

    <ImportQuoteModal
      v-if="showImport"
      :comparison-id="comparison.id"
      @close="showImport = false"
      @imported="onImported"
    />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import { usePermission } from '@/composables/usePermission';
import ImportQuoteModal from '../Modals/ImportQuoteModal.vue';

const props = defineProps({ comparison: Object, quotes: Array });
const { hasPermission: can } = usePermission();

const showImport = ref(false);
const showHistory = ref(false);

const visibleQuotes = computed(() =>
  showHistory.value ? props.quotes : props.quotes.filter(q => q.is_active_version),
);

function fmt(v) {
  return new Intl.NumberFormat('vi-VN').format(Math.round(v || 0));
}

function onImported() {
  showImport.value = false;
  router.reload({ preserveScroll: true });
}

function removeQuote(q) {
  if (!confirm(`Gỡ báo giá của ${q.supplier} (v${q.version_no})?`)) return;
  router.delete(route('purchasing.quote-comparisons.quotes.delete', { quoteComparison: props.comparison.id, quote: q.id }), {
    preserveScroll: true,
  });
}
</script>
