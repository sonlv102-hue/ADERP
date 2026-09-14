<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="erp-page-header">
        <h1 class="text-2xl font-bold text-gray-900">So sánh báo giá NCC</h1>
        <Link
          v-if="can('purchases.quote_comparisons.create')"
          :href="route('purchasing.quote-comparisons.create')"
          class="erp-btn-primary"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
          </svg>
          Tạo đợt so sánh
        </Link>
      </div>

      <div class="flex gap-3 flex-wrap">
        <input
          v-model="search"
          type="text"
          placeholder="Tìm theo mã / tên đợt..."
          class="erp-input w-full sm:w-72"
          @keyup.enter="applyFilters"
        />
        <select v-model="status" class="erp-input w-full sm:w-auto" @change="applyFilters">
          <option value="">-- Tất cả trạng thái --</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tên đợt so sánh</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày so sánh</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số NCC</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Số mặt hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Người tạo</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="c in comparisons.data" :key="c.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono font-medium text-primary-700">{{ c.code }}</td>
              <td class="px-5 py-3 text-gray-900">{{ c.name }}</td>
              <td class="px-5 py-3 text-gray-600">{{ c.comparison_date }}</td>
              <td class="px-5 py-3 text-right text-gray-900">{{ c.supplier_count }}</td>
              <td class="px-5 py-3 text-right text-gray-900">{{ c.item_count }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="c.status_color">{{ c.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-gray-600">{{ c.creator }}</td>
              <td class="px-5 py-3 text-right whitespace-nowrap">
                <Link
                  :href="route('purchasing.quote-comparisons.show', c.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium"
                >Xem</Link>
              </td>
            </tr>
            <tr v-if="!comparisons.data?.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Chưa có đợt so sánh nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="comparisons.links" :meta="comparisons.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import { usePermission } from '@/composables/usePermission';

const props = defineProps({ comparisons: Object, filters: Object, statuses: Array });
const { hasPermission: can } = usePermission();

const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? '');

function applyFilters() {
  router.get(
    route('purchasing.quote-comparisons.index'),
    { search: search.value, status: status.value },
    { preserveState: true, replace: true },
  );
}
</script>
