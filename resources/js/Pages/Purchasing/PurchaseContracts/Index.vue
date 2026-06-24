<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Hợp đồng mua</h1>
        <div class="flex gap-2 flex-wrap">
          <ExportExcelButton :endpoint="route('purchasing.purchase-contracts.export-excel')" :filters="{ q: search, status: statusFilter }" />
          <Link v-if="can('purchasing.create')" :href="route('purchasing.purchase-contracts.create')" class="erp-btn-primary flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Tạo hợp đồng mua
          </Link>
        </div>
      </div>

      <!-- Search -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @input="doSearch" type="text"
          placeholder="Tìm hợp đồng, nhà cung cấp, mã chứng từ..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-full sm:w-72 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="statusFilter" @change="doSearch"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <button v-if="search || statusFilter" @click="clearSearch"
          class="text-gray-500 hover:text-gray-700 px-3 py-2 text-sm">Xóa lọc</button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã HĐ</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Tiêu đề</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Nhà cung cấp</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Đơn mua</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Giá trị</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hiệu lực</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="c in contracts.data" :key="c.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ c.code }}</td>
              <td class="px-5 py-3 font-medium text-gray-900 max-w-xs truncate">{{ c.title }}</td>
              <td class="px-5 py-3 text-gray-600">{{ c.supplier }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-600">{{ c.order_code ?? '—' }}</td>
              <td class="px-5 py-3 text-right text-gray-700">{{ formatVnd(c.value) }}</td>
              <td class="px-5 py-3 text-gray-500 text-xs">
                {{ c.start_date ?? '—' }}
                <span v-if="c.end_date"> → {{ c.end_date }}</span>
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="c.status_color">{{ c.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('purchasing.purchase-contracts.show', c.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!contracts.data?.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Chưa có hợp đồng mua nào</td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="contracts.links" :meta="contracts.meta" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Components/Layout/AppLayout.vue';
import StatusBadge from '@/Components/Shared/StatusBadge.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import ExportExcelButton from '@/Components/Shared/ExportExcelButton.vue';
import { usePermission } from '@/composables/usePermission';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ contracts: Object, filters: Object, statuses: Array });

const { hasPermission } = usePermission();
const can = hasPermission;

const { formatVnd } = useCurrency();

const search       = ref(props.filters?.q ?? '');
const statusFilter = ref(props.filters?.status ?? '');
let searchTimer    = null;

function doSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    router.get(route('purchasing.purchase-contracts.index'), {
      q:      search.value || undefined,
      status: statusFilter.value || undefined,
    }, { preserveState: true, replace: true });
  }, 300);
}
function clearSearch() {
  search.value       = '';
  statusFilter.value = '';
  doSearch();
}
</script>
