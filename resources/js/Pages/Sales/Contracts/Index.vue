<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Hợp đồng</h1>
        <Link :href="route('sales.contracts.create')"
          class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Tạo hợp đồng
        </Link>
      </div>

      <!-- Search -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @input="doSearch" type="text"
          placeholder="Tìm hợp đồng, khách hàng, mã chứng từ..."
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
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Khách hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">ĐH liên kết</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Giá trị</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Thời hạn</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="c in contracts.data" :key="c.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ c.code }}</td>
              <td class="px-5 py-3 text-gray-800 max-w-xs truncate">{{ c.title }}</td>
              <td class="px-5 py-3 text-gray-700">{{ c.customer }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-500">{{ c.order_code ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-medium text-gray-800">{{ formatVnd(c.value) }}</td>
              <td class="px-5 py-3 text-gray-600 text-xs">
                {{ c.start_date ?? '—' }} → {{ c.end_date ?? '—' }}
              </td>
              <td class="px-5 py-3">
                <StatusBadge :color="c.status_color">{{ c.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right flex items-center justify-end gap-3">
                <Link :href="route('sales.contracts.show', c.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium">Xem</Link>
                <button v-if="c.status === 'draft'" @click="deleteContract(c)"
                  class="text-red-500 hover:text-red-700 font-medium">Xóa</button>
              </td>
            </tr>
            <tr v-if="!contracts.data?.length">
              <td colspan="8" class="px-5 py-10 text-center text-gray-400">Chưa có hợp đồng nào</td>
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
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ contracts: Object, filters: Object, statuses: Array });

const { formatVnd } = useCurrency();

const search       = ref(props.filters?.q ?? '');
const statusFilter = ref(props.filters?.status ?? '');
let searchTimer    = null;

function doSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    router.get(route('sales.contracts.index'), {
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

const deleteContract = (c) => {
  if (confirm(`Xóa hợp đồng ${c.code}? Thao tác không thể hoàn tác.`)) {
    router.delete(route('sales.contracts.destroy', c.id));
  }
};
</script>
