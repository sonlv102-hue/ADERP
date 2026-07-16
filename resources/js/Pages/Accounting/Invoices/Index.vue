<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-gray-900">Hóa đơn</h1>
        <div class="flex gap-2 flex-wrap">
          <ExportExcelButton :endpoint="route('accounting.invoices.export-excel')" :filters="{ search: search, status: statusFilter }" />
          <Link v-if="can('sales.invoices.create')" :href="route('accounting.invoices.create')" class="erp-btn-primary">
            Tạo hóa đơn
          </Link>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex gap-3">
        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Tìm mã, khách hàng..."
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="statusFilter" @change="applyFilters"
          class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <button @click="applyFilters" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
          Lọc
        </button>
        <button v-if="filters.search || filters.status" @click="clearFilters"
          class="text-gray-500 hover:text-gray-700 px-3 py-2 text-sm">Xóa lọc</button>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Mã HD</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Khách hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Ngày phát hành</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Hạn TT</th>
              <th class="text-right px-5 py-3 font-semibold text-gray-600">Tổng tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-gray-600">Trạng thái</th>
              <th class="px-5 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="inv in invoices.data" :key="inv.id" class="hover:bg-gray-50">
              <td class="px-5 py-3 font-mono font-medium text-primary-700">{{ inv.code }}</td>
              <td class="px-5 py-3 text-gray-900">{{ inv.customer }}</td>
              <td class="px-5 py-3 text-gray-600">{{ inv.issue_date }}</td>
              <td class="px-5 py-3 text-gray-600">{{ inv.due_date ?? '—' }}</td>
              <td class="px-5 py-3 text-right font-medium text-gray-900">{{ formatVnd(inv.total) }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="inv.status_color">{{ inv.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('accounting.invoices.show', inv.id)"
                  class="text-primary-600 hover:text-primary-800 text-xs font-medium">Xem</Link>
              </td>
            </tr>
            <tr v-if="!invoices.data?.length">
              <td colspan="7" class="px-5 py-10 text-center text-gray-400">Chưa có hóa đơn nào</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :links="invoices.links" :meta="invoices.meta" />
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

const props = defineProps({
  invoices: Object,
  filters:  Object,
  statuses: Array,
});

const { hasPermission } = usePermission();
const can = hasPermission;
const { formatVnd } = useCurrency();

const search       = ref(props.filters?.search ?? '');
const statusFilter = ref(props.filters?.status ?? '');

function applyFilters() {
  router.get(route('accounting.invoices.index'), {
    search: search.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, replace: true });
}

function clearFilters() {
  search.value = '';
  statusFilter.value = '';
  applyFilters();
}

</script>
