<template>
  <AppLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between flex-wrap gap-y-3">
        <h1 class="text-2xl font-bold text-slate-900">Báo giá</h1>
        <div class="flex gap-2 flex-wrap">
          <ExportExcelButton :endpoint="route('sales.quotations.export-excel')" :filters="{ q: search, status: statusFilter }" />
          <Link :href="route('sales.quotations.create')" class="erp-btn-primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Tạo báo giá
          </Link>
        </div>
      </div>

      <!-- Search -->
      <div class="flex gap-3 flex-wrap">
        <input v-model="search" @input="doSearch" type="text"
          placeholder="Tìm báo giá, khách hàng, mã chứng từ..."
          class="border border-slate-300 rounded-lg px-3 py-2 text-sm w-full sm:w-72 focus:outline-none focus:ring-2 focus:ring-primary-500" />
        <select v-model="statusFilter" @change="doSearch"
          class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500">
          <option value="">Tất cả trạng thái</option>
          <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
        <button v-if="search || statusFilter" @click="clearSearch"
          class="text-slate-500 hover:text-slate-700 px-3 py-2 text-sm">Xóa lọc</button>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 border-b border-slate-200">
            <tr>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Mã BG</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Khách hàng</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Hiệu lực đến</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Số dòng</th>
              <th class="text-right px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Tổng tiền</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Trạng thái</th>
              <th class="text-left px-5 py-3 font-semibold text-slate-500 text-xs uppercase tracking-wide">Người tạo</th>
              <th class="px-5 py-3" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="q in quotations.data" :key="q.id" class="hover:bg-slate-50/70 transition-colors">
              <td class="px-5 py-3 font-mono text-xs font-semibold text-slate-700">{{ q.code }}</td>
              <td class="px-5 py-3 text-slate-800 font-medium">{{ q.customer }}</td>
              <td class="px-5 py-3 text-slate-600">{{ q.valid_until ?? '—' }}</td>
              <td class="px-5 py-3 text-slate-600">{{ q.items_count }}</td>
              <td class="px-5 py-3 text-right font-semibold text-slate-800">{{ formatVnd(q.total) }}</td>
              <td class="px-5 py-3">
                <StatusBadge :color="q.status_color">{{ q.status_label }}</StatusBadge>
              </td>
              <td class="px-5 py-3 text-slate-500">{{ q.creator }}</td>
              <td class="px-5 py-3 text-right">
                <Link :href="route('sales.quotations.show', q.id)"
                  class="text-primary-600 hover:text-primary-800 font-medium text-xs">Xem →</Link>
              </td>
            </tr>
            <tr v-if="!quotations.data?.length">
              <td colspan="8" class="px-5 py-14 text-center text-slate-400">
                <svg class="w-8 h-8 mx-auto mb-2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Chưa có báo giá nào
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <Pagination :links="quotations.links" :meta="quotations.meta" />
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
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps({ quotations: Object, filters: Object, statuses: Array });

const { formatVnd } = useCurrency();

const search       = ref(props.filters?.q ?? '');
const statusFilter = ref(props.filters?.status ?? '');
let searchTimer    = null;

function doSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    router.get(route('sales.quotations.index'), {
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
